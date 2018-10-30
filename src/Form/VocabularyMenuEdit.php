<?php

namespace Drupal\taxonomy_menu_ui\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy_menu_ui\TaxonomyMenuUIHelper;

/**
 * Configure taxonomy_menu_ui settings for this site.
 */
class VocabularyMenuEdit extends ConfigFormBase {

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
      '#description' => t('Remove menu items created from term list current vocabulary.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('taxonomy_menu_ui.settings')
      ->set('menu_list', [
        $form_state->get('taxonomy_vocabulary') => [
          'menu_name' => $form_state->getValue('menu_name'),
          'menu_parent' => $form_state->getValue('menu_parent'),
          'link_default' => [
            'title' => $form_state->getValue('title'),
            'description' => $form_state->getValue('description'),
          ],
        ],
      ])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
