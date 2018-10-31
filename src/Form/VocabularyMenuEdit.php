<?php

namespace Drupal\taxonomy_menu_ui\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Token;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\taxonomy_menu_ui\TaxonomyMenuUIHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure taxonomy_menu_ui settings for this site.
 */
class VocabularyMenuEdit extends ConfigFormBase {

  /** @var int Count created menu */
  protected $items = 0;

  /** @var EntityTypeManager */
  protected $entityTypeManager;

  /** @var Token */
  protected $token;

  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManager $entityTypeManager, Token $token) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entityTypeManager;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'taxonomy_menu_ui_vocabulary_menu_edit';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['taxonomy_menu_ui.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $taxonomy_vocabulary = NULL) {

    $config = $this->config('taxonomy_menu_ui.settings');
    $form_state->set('taxonomy_vocabulary', $taxonomy_vocabulary);

    $current_settings = $config->get('menu_list.' . $taxonomy_vocabulary);

    $form['menu'] = [
      '#type' => 'details',
      '#title' => t('Menu settings'),
      '#attached' => [
        'library' => ['menu_ui/drupal.menu_ui.admin'],
      ],
      '#group' => 'additional_settings',
      '#open' => $current_settings['menu_name'] ? TRUE : FALSE,
    ];

    $form['menu']['menu_name'] = [
      '#type' => 'radios',
      '#title' => t('Available menus'),
      '#options' => menu_ui_get_menus(),
      '#default_value' => $current_settings['menu_name'],
      '#description' => t('The menus available to place links in for this content type.'),
    ];

    /** @var \Drupal\Core\Menu\MenuParentFormSelectorInterface $menu_parent_selector */
    $menu_parent_selector = \Drupal::service('menu.parent_form_selector');

    // @todo See if we can avoid pre-loading all options by changing the form or
    //   using a #process callback. https://www.drupal.org/node/2310319
    //   To avoid an 'illegal option' error after saving the form we have to load
    //   all available menu parents. Otherwise, it is not possible to dynamically
    //   add options to the list using ajax.
    $options_cacheability = new CacheableMetadata();
    $options = $menu_parent_selector->getParentSelectOptions('', NULL, $options_cacheability);
    $form['menu']['menu_parent'] = [
      '#type' => 'select',
      '#title' => t('Default parent item'),
      '#default_value' => $current_settings['menu_parent'],
      '#options' => $options,
      '#description' => t('Choose the menu item to be the default parent for a new link in the content authoring form.'),
      '#attributes' => ['class' => ['menu-title-select']],
    ];
    $options_cacheability->applyTo($form['menu']['menu_parent']);

    $linkSettings = isset($current_settings['link_default']) ? $current_settings['link_default'] : NULL;
    TaxonomyMenuUIHelper::MenuItemSetings($form, [
      'title' => isset($linkSettings['title']) ? $linkSettings['title'] : NULL,
      'description' => isset($linkSettings['description']) ? $linkSettings['description'] : NULL,
    ]);

    $form['run_generate'] = [
      '#type' => 'checkbox',
      '#title' => t('Menu auto generate'),
      '#description' => t('Generate menu items after save from term list current vocabulary.'),
    ];

    $form['actions']['remove_menu'] = [
      '#type' => 'submit',
      '#value' => t('Remove menu items'),
      '#suffix' => '<i>' . t('Remove menu items created from term list current vocabulary.') . '</i>',
      '#weight' => 500,
      '#submit' => ['::removeMenuItems'],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function removeMenuItems(array &$form, FormStateInterface $form_state) {
    $mids = $this->config('taxonomy_menu_ui.settings')
      ->get("menu_list.{$form_state->get('taxonomy_vocabulary')}.links");
    if ($mids) {
      /** @var MenuLinkContent $menu */
      foreach (MenuLinkContent::loadMultiple($mids) as $menu) {
        $tid = array_search($menu->id(), $mids);
        $menu->delete();
        if ($tid !== FALSE) {
          $this->config('taxonomy_menu_ui.settings')
            ->clear("menu_list.{$form_state->get('taxonomy_vocabulary')}.links.{$tid}")
            ->save();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $link_default = [
      'title' => $form_state->getValue('title'),
      'description' => $form_state->getValue('description'),
    ];
    $vid = $form_state->get('taxonomy_vocabulary');
    $this->config('taxonomy_menu_ui.settings')
      ->set("menu_list.{$vid}", [
        'menu_name' => $form_state->getValue('menu_name'),
        'menu_parent' => $form_state->getValue('menu_parent'),
        'link_default' => $link_default,
      ])
      ->save();

    if ($form_state->getValue('run_generate')) {
      $this->autoGenetateMenu($vid, $link_default, $form_state->getValue('menu_parent'));
      $this->messenger()
        ->addStatus($this->t('Created @count menu items', ['@count' => $this->items]));
    }

    parent::submitForm($form, $form_state);
  }

  public function autoGenetateMenu($vid, $link_default, $menu_parent, $parent_term = 0) {
    $helper = new TaxonomyMenuUIHelper();
    $terms = \Drupal::service('entity_type.manager')
      ->getStorage("taxonomy_term")
      ->loadTree($vid, $parent_term, 1, TRUE);
    /** @var \Drupal\taxonomy\Entity\Term $term */
    foreach ($terms as $term) {
      $values = [
        'title' => $this->token->replace($link_default['title'], ['term' => $term]),
        'description' => $this->token->replace($link_default['description'], ['term' => $term]),
        'menu_parent' => $menu_parent,
      ];
      $helper->createTaxonomyMenuLink($values, $term);
      $this->items++;
      $menuData = $helper->getMenuLinkDefault($term);
      $this->autoGenetateMenu($vid, $link_default, $menuData['link_uuid'],$term->id());
    }
  }

}
