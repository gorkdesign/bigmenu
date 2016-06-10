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
//    $menu_link = 'menu_link_content:eeb3b069-6a58-4b8b-9915-59559e240201';
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

    if (!empty($this->tree)) {
      drupal_set_message('here');
      drupal_set_message($this->printTree($this->tree));
      drupal_set_message('end-here');
    }

    // Get the menu tree
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

  public function getSubtree($depth, $root) {
    // Get the slice of the subtree that we're looking for.
    $slice_tree = $this->getTree($depth, $root);

    // Add it to the larger tree we're rendering.
    $root_key = key($slice_tree);
    $this->tree[$root_key]->subtree = &$slice_tree[$root_key]->subtree;
    drupal_set_message('whaddup');
    drupal_set_message($this->printTree($this->tree));
    drupal_set_message('end whaddup');
  }

  public function process_links(&$form, $links, $menu_link) {
    foreach (Element::children($links) as $id) {
      if (isset($links[$id]['#item']) && !isset($form['links'][$id]['#item'])) {
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
          if (!$menu_link || $menu_link->id() != $mlid) {
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

    $this->getSubtree(10, $menu_link);

    // Add a command to execute on form, jQuery .html() replaces content between tags.
    $ajax_response->addCommand(new HtmlCommand('#block-seven-content', $this->buildOverviewForm($form, $form_state, 1)));

    // Return the AjaxResponse Object.
    return $ajax_response;
  }

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

  /**
   * Recursive helper function for buildOverviewForm().
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The tree retrieved by \Drupal\Core\Menu\MenuLinkTreeInterface::load().
   * @param int $delta
   *   The default number of menu items used in the menu weight selector is 50.
   *
   * @return array
   *   The overview tree form.
   */
  protected function buildOverviewTreeForm($tree, $delta) {
    $form = &$this->overviewTreeForm;
    foreach ($tree as $element) {
      // Only render accessible links.
      if (!$element->access->isAllowed()) {
        continue;
      }

      /** @var \Drupal\Core\Menu\MenuLinkInterface $link */
      $link = $element->link;
      if ($link) {
        $id = 'menu_plugin_id:' . $link->getPluginId();
        $form[$id]['#item'] = $element;
        $form[$id]['#attributes'] = $link->isEnabled() ? array('class' => array('menu-enabled')) : array('class' => array('menu-disabled'));
        $form[$id]['title'] = Link::fromTextAndUrl($link->getTitle(), $link->getUrlObject())->toRenderable();

        if (!$link->isEnabled()) {
          $form[$id]['title']['#suffix'] = ' (' . $this->t('disabled') . ')';
        }
        // @todo Remove this in https://www.drupal.org/node/2568785.
        elseif ($id === 'menu_plugin_id:user.logout') {
          $form[$id]['title']['#suffix'] = ' (' . $this->t('<q>Log in</q> for anonymous users') . ')';
        }
        // @todo Remove this in https://www.drupal.org/node/2568785.
        elseif (($url = $link->getUrlObject()) && $url->isRouted() && $url->getRouteName() == 'user.page') {
          $form[$id]['title']['#suffix'] = ' (' . $this->t('logged in users only') . ')';
        }

        $form[$id]['enabled'] = array(
          '#type' => 'checkbox',
          '#title' => $this->t('Enable @title menu link', array('@title' => $link->getTitle())),
          '#title_display' => 'invisible',
          '#default_value' => $link->isEnabled(),
        );
        $form[$id]['weight'] = array(
          '#type' => 'weight',
          '#delta' => $delta,
          '#default_value' => $link->getWeight(),
          '#title' => $this->t('Weight for @title', array('@title' => $link->getTitle())),
          '#title_display' => 'invisible',
        );
        $form[$id]['id'] = array(
          '#type' => 'hidden',
          '#value' => $link->getPluginId(),
        );
        $form[$id]['parent'] = array(
          '#type' => 'hidden',
          '#default_value' => $link->getParent(),
        );
        // Build a list of operations.
        $operations = array();
        $operations['edit'] = array(
          'title' => $this->t('Edit'),
        );
        // Allow for a custom edit link per plugin.
        $edit_route = $link->getEditRoute();
        if ($edit_route) {
          $operations['edit']['url'] = $edit_route;
          // Bring the user back to the menu overview.
          $operations['edit']['query'] = $this->getDestinationArray();
        }
        else {
          // Fall back to the standard edit link.
          $operations['edit'] += array(
            'url' => Url::fromRoute('menu_ui.link_edit', ['menu_link_plugin' => $link->getPluginId()]),
          );
        }
        // Links can either be reset or deleted, not both.
        if ($link->isResettable()) {
          $operations['reset'] = array(
            'title' => $this->t('Reset'),
            'url' => Url::fromRoute('menu_ui.link_reset', ['menu_link_plugin' => $link->getPluginId()]),
          );
        }
        elseif ($delete_link = $link->getDeleteRoute()) {
          $operations['delete']['url'] = $delete_link;
          $operations['delete']['query'] = $this->getDestinationArray();
          $operations['delete']['title'] = $this->t('Delete');
        }
        if ($link->isTranslatable()) {
          $operations['translate'] = array(
            'title' => $this->t('Translate'),
            'url' => $link->getTranslateRoute(),
          );
        }
        $form[$id]['operations'] = array(
          '#type' => 'operations',
          '#links' => $operations,
        );
      }

      if ($element->subtree) {
        $this->buildOverviewTreeForm($element->subtree, $delta);
      }
    }

    return $form;
  }
}
