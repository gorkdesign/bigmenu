<?php

/**
 * @file
 * Contains \Drupal\bigmenu\MenuFormController.
 */

namespace Drupal\bigmenu;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\menu_ui\MenuForm as DefaultMenuFormController;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Render\Element;

/**
 * Class MenuFormController
 * @package Drupal\bigmenu
 */
class MenuFormController extends DefaultMenuFormController
{
  public $tree = array();

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param int $depth
   * @param NULL $menuOpen
   * @return array
   */
  protected function buildOverviewForm(array &$form, FormStateInterface $form_state, $depth = 1, \Drupal\menu_link_content\Entity\MenuLinkContent $menu_link = NULL)
  {
    // Ensure that menu_overview_form_submit() knows the parents of this form
    // section.
    if (!$form_state->has('menu_overview_form_parents')) {
      $form_state->set('menu_overview_form_parents', []);
    }

    // Use Menu UI adminforms
    $form['#attached']['library'][] = 'menu_ui/drupal.menu_ui.adminforms';

    $form['links'] = array(
      '#type' => 'table',
      '#theme' => 'table__menu_overview',
      '#header' => array(
        $this->t('Menu link'),
        array(
          'data' => $this->t('Enabled'),
          'class' => array('checkbox'),
        ),
        $this->t('Weight'),
        array(
          'data' => $this->t('Operations'),
          'colspan' => 3,
        ),
      ),
      '#attributes' => array(
        'id' => 'menu-overview',
      ),
      '#tabledrag' => array(
        array(
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'menu-parent',
          'subgroup' => 'menu-parent',
          'source' => 'menu-id',
          'hidden' => TRUE,
          'limit' => \Drupal::menuTree()->maxDepth() - 1,
        ),
        array(
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'menu-weight',
        ),
      ),
    );

    // No Links available (Empty menu)
    $form['links']['#empty'] = $this->t('There are no menu links yet. <a href=":url">Add link</a>.', [
      ':url' => $this->url('entity.menu.add_link_form', ['menu' => $this->entity->id()], [
        'query' => ['destination' => $this->entity->url('edit-form')],
      ]),
    ]);

    // Get the menu tree if it's not in our property.
    if (empty($this->tree)) {
      $this->tree = $this->getTree($depth);
    }

    // Determine the delta; the number of weights to be made available.
    $count = function (array $tree) {
      $sum = function ($carry, MenuLinkTreeElement $item) {
        return $carry + $item->count();
      };
      return array_reduce($tree, $sum);
    };

    // Tree maximum or 50.
    $delta = max($count($this->tree), 50);

    $links = $this->buildOverviewTreeForm($this->tree, $delta);

    $this->process_links($form, $links, $menu_link);

    return $form;
  }

  /**
   * Gets the menu tree.
   *
   * @param $depth
   * @param null $root
   * @return \Drupal\Core\Menu\MenuLinkTreeElement[]
   */
  public function getTree($depth, $root = null) {
    $tree_params = new MenuTreeParameters();
    $tree_params->setMaxDepth($depth);

    if ($root) {
      $tree_params->setRoot($root->getPluginId());
    }

    $tree = $this->menuTree->load($this->entity->id(), $tree_params);

    // We indicate that a menu administrator is running the menu access check.
    $this->getRequest()->attributes->set('_menu_admin', TRUE);
    $manipulators = array(
      array('callable' => 'menu.default_tree_manipulators:checkAccess'),
      array('callable' => 'menu.default_tree_manipulators:generateIndexAndSort'),
    );
    $tree = $this->menuTree->transform($tree, $manipulators);
    $this->getRequest()->attributes->set('_menu_admin', FALSE);

    return $tree;
  }

  /**
   * Append a child's subtree to the parent tree.
   *
   * @param $depth
   * @param $root
   */
  public function appendSubtree($depth, $root) {
    // Clear out the overview tree form which has the old tree info in it.
    $this->overviewTreeForm = array('#tree' => TRUE);

    // Get the slice of the subtree that we're looking for.
    $slice_tree = $this->getTree($depth, $root);

    // Add it to the larger tree we're rendering.
    $root_key = key($slice_tree);
    $this->tree[$root_key]->subtree = &$slice_tree[$root_key]->subtree;
  }

  /**
   * Format the links appropriately so draggable views will work.
   * @param $form
   * @param $links
   * @param $menu_link
   */
  public function process_links(&$form, &$links, $menu_link) {
    foreach (Element::children($links) as $id) {
      if (isset($links[$id]['#item'])) {
        $element = $links[$id];

        $form['links'][$id]['#item'] = $element['#item'];

        // TableDrag: Mark the table row as draggable.
        $form['links'][$id]['#attributes'] = $element['#attributes'];
        $form['links'][$id]['#attributes']['class'][] = 'draggable';

        // TableDrag: Sort the table row according to its existing/configured weight.
        $form['links'][$id]['#weight'] = $element['#item']->link->getWeight();

        // Add special classes to be used for tabledrag.js.
        $element['parent']['#attributes']['class'] = array('menu-parent');
        $element['weight']['#attributes']['class'] = array('menu-weight');
        $element['id']['#attributes']['class'] = array('menu-id');

        $form['links'][$id]['title'] = array(
          array(
            '#theme' => 'indentation',
            '#size' => $element['#item']->depth - 1,
          ),
          $element['title'],
        );
        $form['links'][$id]['enabled'] = $element['enabled'];
        $form['links'][$id]['enabled']['#wrapper_attributes']['class'] = array('checkbox', 'menu-enabled');

        $form['links'][$id]['weight'] = $element['weight'];

        // Operations (dropbutton) column.
        $form['links'][$id]['operations'] = $element['operations'];

        $form['links'][$id]['id'] = $element['id'];
        $form['links'][$id]['parent'] = $element['parent'];

        $mlid = (int)$links[$id]['#item']->link->getMetaData()['entity_id'];

        if ($form['links'][$id]['#item']->hasChildren) {
          if (is_null($menu_link) || (isset($menu_link) && $menu_link->id() != $mlid)) {
            $form['links'][$id]['title'][] = array(
              '#type' => 'big_menu_button',
              '#title' => t('Show Children'),
              '#value' => 'Edit Children',
              '#name' => $mlid,
              '#attributes' => array('mlid' => $mlid),
              '#url' => '#',
              '#description' => t('Show children'),
              '#ajax' => array(
                // Function to call when event on form element triggered.
                'callback' => array(
                  $this,
                  'Drupal\bigmenu\MenuFormController::bigmenu_ajax_callback'
                ),
                // Effect when replacing content. Options: 'none' (default), 'slide', 'fade'.
                'effect' => 'none',
                // Javascript event to trigger Ajax. Currently for: 'onchange'.
                'event' => 'click',
                'progress' => array(
                  // Graphic shown to indicate ajax. Options: 'throbber' (default), 'bar'.
                  'type' => 'throbber',
                  // Message to show along progress graphic. Default: 'Please wait...'.
                  'message' => NULL,
                ),
              ),
            );
          }
        }
      }
    }
  }

  /**
   * AJAX function called when a "Show Children" button is pressed.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return AjaxResponse
   */
  public function bigmenu_ajax_callback(array &$form, \Drupal\Core\Form\FormStateInterface &$form_state)
  {
    $elem = $form_state->getTriggeringElement();
    $menuLinkId = $elem['#attributes']['mlid'];

    $menu_link = \Drupal::entityTypeManager()->getStorage('menu_link_content')->load($menuLinkId);

    // Instantiate an AjaxResponse Object to return.
    $ajax_response = new AjaxResponse();

    $form_state->setRebuild(TRUE);

    $this->appendSubtree(10, $menu_link);

    // Add a command to execute on form, jQuery .html() replaces content between tags.
//    $ajax_response->addCommand(new HtmlCommand('.menu-edit-bigmenu-form menu-form', $this->buildOverviewForm($form, $form_state, 1)));
    $ajax_response->addCommand(new HtmlCommand('#block-seven-content', $this->buildOverviewForm($form, $form_state, 1)));

    // Return the AjaxResponse Object.
    return $ajax_response;
  }

  /**
   * Header function to print the tree.
   * @param $tree
   */
  public function printTree($tree) {
    foreach ($tree as $key => $leaf) {
      drupal_set_message($key . " count: " . $leaf->count());
      if ($leaf->count() > 1) {
        drupal_set_message('---subtree---' . $key);
        $this->printTree($leaf->subtree);
        drupal_set_message('---endsubtree---' . $key);
      }
    }
  }

}
