<?php

namespace Drupal\taxonomy_menu_ui;

use Drupal\Core\Form\FormStateInterface;

class TaxonomyMenuUIHelper {

  public function getConfig(){
    return \Drupal::config('taxonomy_menu_ui.settings');
  }

  public function getCurrentTerm(){
    return $route = \Drupal::routeMatch()->getParameter('taxonomy_term');
  }

  public static function MenuItemSetiings(&$form, $config = NULL) {

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

  public static function TaxonomyTermElements(&$form, FormStateInterface $form_state){

    $helper = (new self());
    $config = $helper->getConfig();
    /** @var \Drupal\taxonomy\Entity\Term $term */
    $term = $helper->getCurrentTerm();
    $settings = $config->get('menu_list.'.$term->getVocabularyId());
    /** @var \Drupal\Core\Menu\MenuParentFormSelectorInterface $menu_parent_selector */
    $menu_parent_selector = \Drupal::service('menu.parent_form_selector');
    $parent_element = $menu_parent_selector->parentSelectElement($settings['menu_parent']);
    // If no possible parent menu items were found, there is nothing to display.
    if (empty($parent_element)) {
      return;
    }

    $form['menu'] = [
      '#type' => 'details',
      '#title' => t('Menu settings'),
      '#access' => \Drupal::currentUser()->hasPermission('administer menu'),
      //'#open' => (bool) $defaults['id'],
      '#group' => 'advanced',
      '#attached' => [
        'library' => ['menu_ui/drupal.menu_ui'],
      ],
      '#tree' => TRUE,
      '#weight' => 10,
      '#attributes' => ['class' => ['menu-link-form']],
    ];

    $form['menu']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Provide a menu link'),
      //'#default_value' => (int) (bool) $defaults['id'],
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

    self::MenuItemSetiings($form);

    $form['menu']['link']['menu_parent'] = $parent_element;
    $form['menu']['link']['menu_parent']['#title'] = t('Parent item');
    $form['menu']['link']['menu_parent']['#attributes']['class'][] = 'menu-parent-select';

    $form['menu']['link']['weight'] = [
      '#type' => 'number',
      '#title' => t('Weight'),
      //'#default_value' => $defaults['weight'],
      '#description' => t('Menu links with lower weights are displayed before links with higher weights.'),
    ];
  }

  public static function VocabularyBuilder() {
    dsm(__FUNCTION__);
  }

}