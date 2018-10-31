<?php

namespace Drupal\taxonomy_menu_ui;

use Drupal\Core\Form\FormStateInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;

class TaxonomyMenuUIHelper {

  /**
   * Return config "taxonomy_menu_ui.settings" for edit(editable).
   *
   * @return \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   *   A configuration object.
   */
  public function getConfig() {
    return \Drupal::service('config.factory')
      ->getEditable('taxonomy_menu_ui.settings');
  }

  /**
   * Return current term from route.
   *
   * @return mixed|null|\Drupal\taxonomy\Entity\Term
   *   Current term
   */
  public function getCurrentTerm() {
    return \Drupal::routeMatch()->getParameter('taxonomy_term');
  }

  /**
   * Add link elements in Form array.
   *
   * @param array $form
   *   Form elements array.
   * @param array|null $config
   *   Data for default value.
   *
   * @see \Drupal\taxonomy_menu_ui\Form\VocabularyMenuEdit::buildForm()
   * @see TaxonomyTermElements()
   *
   */
  public static function MenuItemSetings(&$form, $config = NULL) {

    $form['menu']['token_tree'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => [
        'site',
        'term',
      ],
      '#show_restricted' => TRUE,
      '#global_types' => FALSE,
    ];

    $form['menu']['link']['title'] = [
      '#type' => 'textfield',
      '#title' => t('Default title for menu item'),
      '#default_value' => isset($config['title']) ? $config['title'] : NULL,
    ];

    $form['menu']['link']['description'] = [
      '#type' => 'textarea',
      '#title' => t('Default description for menu item'),
      '#rows' => 1,
      '#description' => t('Shown when hovering over the menu link.'),
      '#default_value' => isset($config['description']) ? $config['description'] : NULL,
    ];

  }

  /**
   * Add elements in taxonomy_term_form form.
   *
   * @param $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @see taxonomy_menu_ui_form_taxonomy_term_form_alter()
   */
  public static function TaxonomyTermElements(&$form, FormStateInterface $form_state) {

    $helper = (new self());
    $config = $helper->getConfig();
    $defaults = $helper->getMenuLinkDefault();
    /** @var \Drupal\taxonomy\Entity\Term $term */
    $term = $helper->getCurrentTerm();

    if (!$term) {
      return;
    }

    $parent_menu = isset($defaults['parent']) ? $defaults['parent'] : $config->get('menu_list.' . $term->getVocabularyId() . '.menu_parent');
    if (empty($parent_menu)) {
      return;
    }
    /** @var \Drupal\Core\Menu\MenuParentFormSelectorInterface $menu_parent_selector */
    $menu_parent_selector = \Drupal::service('menu.parent_form_selector');
    $parent_element = $menu_parent_selector->parentSelectElement($parent_menu);
    // If no possible parent menu items were found, there is nothing to display.
    if (empty($parent_element)) {
      return;
    }

    $form['menu'] = [
      '#type' => 'details',
      '#title' => t('Menu settings'),
      '#access' => \Drupal::currentUser()->hasPermission('administer menu'),
      '#open' => $defaults ? (bool) $defaults['id'] : FALSE,
      '#group' => 'advanced',
      '#attached' => [
        'library' => ['menu_ui/drupal.menu_ui'],
      ],
      '#tree' => TRUE,
      '#weight' => 10,
      '#attributes' => ['class' => ['menu-link-form']],
      '#disabled' => !(bool) $config->get('menu_list.' . $term->getVocabularyId() . '.individ_settings'),
    ];

    $form['menu']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Provide a menu link'),
      '#default_value' => $defaults ? (int) (bool) $defaults['id'] : FALSE,
    ];

    $form['menu']['link'] = [
      '#type' => 'container',
      '#parents' => ['menu'],
      '#states' => [
        'invisible' => [
          'input[name="menu[enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    self::MenuItemSetings($form, $defaults ?: NULL);

    $form['menu']['link']['menu_parent'] = $parent_element;
    $form['menu']['link']['menu_parent']['#title'] = t('Parent item');
    $form['menu']['link']['menu_parent']['#attributes']['class'][] = 'menu-parent-select';

    $form['menu']['link']['weight'] = [
      '#type' => 'number',
      '#title' => t('Weight'),
      '#default_value' => $defaults['weight'],
      '#description' => t('Menu links with lower weights are displayed before links with higher weights.'),
    ];

    foreach (array_keys($form['actions']) as $action) {
      if ($action != 'preview' && isset($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
        $form['actions'][$action]['#submit'][] = [
          $helper,
          'taxonomyTermSubmit'
        ];
      }
    }

    $form['#validate'][] = [$helper, 'taxonomyTermSubmitValidate'];

  }

  public function taxonomyTermSubmitValidate($form, FormStateInterface $form_state) {
    $values = $form_state->getValue('menu');
    if (empty($values['title'])) {
      $form_state->setErrorByName('menu][title', t('Link title cannot empty.'));
    }
  }

  public function taxonomyTermSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValue('menu');
    if ($values['enabled']) {
      $this->createTaxonomyMenuLink($values);
    }
    else {
      $this->deleteTaxonomyMenuLink();
    }
  }

  public function createTaxonomyMenuLink($values, $term = FALSE) {

    list($menu_name, $parent) = explode(':', $values['menu_parent'], 2);
    $values['menu_name'] = $menu_name;
    $values['parent'] = $parent;

    $term = $term ?: $this->getCurrentTerm();
    $this->deleteTaxonomyMenuLink($term);
    /** @var MenuLinkContent $entity */
    $entity = MenuLinkContent::create([
      'link' => [
        'uri' => 'internal:/taxonomy/term/' . $term->id()
      ],
      'langcode' => $term->language()->getId(),
      'enabled' => 1,
    ]);
    $entity->set('title', trim($values['title']))
      ->set('description', trim($values['description']))
      ->set('menu_name', $values['menu_name'])
      ->set('parent', $values['parent'])
      ->set('weight', isset($values['weight']) ? $values['weight'] : 0)
      ->set('expanded', isset($values['expanded']) ? $values['expanded'] : 0)
      ->save();
    $this->getConfig()
      ->set("menu_list.{$term->getVocabularyId()}.links.{$term->id()}", $entity->id())
      ->save();
  }

  public function deleteTaxonomyMenuLink($term = FALSE) {
    $term = $term ?: $this->getCurrentTerm();
    $menu_id = $this->getConfig()
      ->get("menu_list.{$term->getVocabularyId()}.links.{$term->id()}");
    if ($menu_id) {
      $menu = MenuLinkContent::load($menu_id);
      if ($menu) {
        $menu->delete();
      }
      $this->getConfig()
        ->clear("menu_list.{$term->getVocabularyId()}.links.{$term->id()}")
        ->save();
    }
  }

  /**
   * Returns the definition for a menu link for the given term.
   *
   * @return array|false
   *   An array that contains default values for the menu link form or false if not found.
   */
  public function getMenuLinkDefault($term = FALSE) {
    $term = $term ?: $this->getCurrentTerm();
    if ($term) {
      $menu_id = $this->getConfig()
        ->get("menu_list.{$term->getVocabularyId()}.links.{$term->id()}");
      if ($menu_id) {
        $menu = MenuLinkContent::load($menu_id);
        if ($menu) {
          return [
            'id' => $menu_id,
            'title' => $menu->getTitle(),
            'descrition' => $menu->getDescription(),
            'weight' => $menu->getWeight(),
            'link_uuid' => $menu->getMenuName() . ':menu_link_content:' . $menu->get('uuid')->value,
            'parent' => $menu->getMenuName() . ':' . $menu->getParentId(),
          ];
        }
      }
    }

    return FALSE;
  }

}
