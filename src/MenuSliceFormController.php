<?php

/**
 * @file
 * Contains \Drupal\bigmenu\MenuSliceFormController.
 */

namespace Drupal\bigmenu;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AlertCommand;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\menu_link_content\Entity\MenuLinkContent;

class MenuSliceFormController extends MenuFormController {

  /**
   * @var \Drupal\Core\Menu\MenuLinkInterface
   */
  protected $menuLink;


  protected function prepareEntity() {
    $this->menuLink = $this->getRequest()->attributes->get('menu_link');
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param int $depth
   * @return array
   */
  protected function buildOverviewForm(array &$form, \Drupal\Core\Form\FormState $form_state, $depth = 1) {

    // Ensure that menu_overview_form_submit() knows the parents of this form
    // section.
    if (!$form_state->has('menu_overview_form_parents')) {
      $form_state->set('menu_overview_form_parents', []);
    }

    $form['#attached']['library'][] = 'menu_ui/drupal.menu_ui.adminforms';

    // 'edit_bigmenu' == $this->operation

    $tree_params = new MenuTreeParameters();
    $tree_params->setMaxDepth($depth);
    $tree = $this->menuTree->load($this->entity->id(), $tree_params);

    // We indicate that a menu administrator is running the menu access check.
    $this->getRequest()->attributes->set('_menu_admin', TRUE);
    $manipulators = array(
        array('callable' => 'menu.default_tree_manipulators:checkAccess'),
        array('callable' => 'menu.default_tree_manipulators:generateIndexAndSort'),
    );
    $tree = $this->menuTree->transform($tree, $manipulators);
    $this->getRequest()->attributes->set('_menu_admin', FALSE);

    // Determine the delta; the number of weights to be made available.
    $count = function(array $tree) {
      $sum = function ($carry, MenuLinkTreeElement $item) {
        return $carry + $item->count();
      };
      return array_reduce($tree, $sum);
    };

    // Tree maximum or 50.
    $delta = max($count($tree), 50);

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

    $links = $this->buildOverviewTreeForm($tree, $delta);
    foreach (Element::children($links) as $id) {
      if (isset($links[$id]['#item'])) {
        $element = $links[$id];

        $form['links'][$id]['#item'] = $element['#item'];

        // TableDrag: Mark the table row as draggable.
        $form['links'][$id]['#attributes'] = $element['#attributes'];
        $form['links'][$id]['#attributes']['class'][] = 'draggable';

        $form['links'][$id]['#item'] = $element['#item'];

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

        $strings = array(
            '!show_children' => t('Show children'),
            '%count' => 123,
            '!tooltip' => t('Click to expand and show child items'),
        );
        $text = strtr('+ !show_children (%count)', $strings);
        $mlid = (int)$links[$id]['#item']->link->getMetaData()['entity_id'];

        /*
         * Show-more-Link with Ajax.
         */
        $url = Url::fromRoute(
            'bigmenu.menu_link',
            array('menu' => 'main', 'menu_link' => $mlid)
        );
        $url->setOption('attributes', array('class' => 'use-ajax'));
        $link_generator = \Drupal::service('link_generator');
        $indicator = $link_generator->generate($text, $url);

        $form['links'][$id]['enabled'] = $element['enabled'];
        $form['links'][$id]['enabled']['#wrapper_attributes']['class'] = array('checkbox', 'menu-enabled');

        $form['links'][$id]['weight'] = $element['weight'];

        // Operations (dropbutton) column.
        $form['links'][$id]['operations'] = $element['operations'];

        // Menulink
        $form['links'][$id]['id'] = $element['id'];

        // MenuLink Parent
        $form['links'][$id]['parent'] = $element['parent'];

        // Title with Show more link.
        $form['links'][$id]['title'][] = array('#markup'=>'<br />'.$indicator);
      }
    }

    return $form;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   */
  public function form(array $form, \Drupal\Core\Form\FormState &$form_state) {
    $menu = $this->entity;
    $menuLink = $this->menuLink;

    $trigger = $form_state->getTriggeringElement();
    $halt = 'halt';

    // Add menu links administration form for existing menus.
    if (!$menu->isNew() || $menu->isLocked()) {
      // Form API supports constructing and validating self-contained sections
      // within forms, but does not allow to handle the form section's submission
      // equally separated yet. Therefore, we use a $form_state key to point to
      // the parents of the form section.
      // @see self::submitOverviewForm()
//       $form_state['menu_overview_form_parents'] = array('links');
      $form['links'] = array();
      return $this->maxDepthbuildOverviewForm($form['links'], $form_state, 2);
    }

    return $form;
  }


  /**
   * @param array $form
   * @param array $form_state
   * @param int $depth
   * @return array
   */
  protected function maxDepthbuildOverviewForm(array &$form, FormStateInterface $form_state, $depth = 1) {
    // Ensure that menu_overview_form_submit() knows the parents of this form
    // section.
    $form['#tree']  = TRUE;
    $form['#theme'] = 'menu_overview_form';
    //$form_state += array('menu_overview_form_parents' => array());

    $form['#attached']['css'] = array(drupal_get_path('module', 'menu') . '/css/menu.admin.css');

    $menu_tree = \Drupal::menuTree();

    $menu_tree_parameters = new MenuTreeParameters();
    $menu_tree_parameters->minDepth = 0;
    $menu_tree_parameters->maxDepth = $depth;

    $test = MenuLinkContent::load($this->menuLink)->getPluginId();
    $menu_tree_parameters->setRoot($test);

    $tree = \Drupal::menuTree()->load(
      $this->entity->getOriginalId(),
      $menu_tree_parameters
    );

    $m_tree = $this->buildOverviewTreeForm($tree);



    $form = array_merge($form, $m_tree);
    $form = $this->buildOverviewTreeForm($tree);
    $form['#empty_text'] = t('There are no menu links yet. <a href="@link">Add link</a>.', array('@link' => url('admin/structure/menu/manage/' . $this->entity->id() . '/add')));

    return $form;
  }

}
