<?php

namespace Drupal\ttd_topics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\topicalboost\Form\GetTopicsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Admin Node Topics Block' block.
 *
 * @Block(
 *   id = "admin_node_topics_block",
 *   admin_label = @Translation("TopicalBoost - Admin Node Topics Block"),
 *   category = @Translation("Custom"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class AdminNodeTopicsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new AdminNodeTopicsBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    $terms = [];

    if ($node && $node->hasField('field_ttd_topics')) {
      $term_ids = $node->get('field_ttd_topics')->getValue();
      foreach ($term_ids as $term_id) {
        $term = Term::load($term_id['target_id']);
        if ($term) {
          $terms[] = [
            'term' => [
              '#type' => 'checkbox',
              '#title' => $term->getName(),
              '#checked' => TRUE,
            ],
          ];
        }
      }
    }

    $form = new GetTopicsForm();
    $form_state = new FormState();
    $form = $form->buildForm([], $form_state);

    return $form;
  }

}
