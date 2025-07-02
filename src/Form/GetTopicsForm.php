<?php

namespace Drupal\ttd_topics\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Form for getting topics for a node.
 */
class GetTopicsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'get_topics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['get_topics_details'] = [
      '#type' => 'details',
      '#title' => $this->t('TopicalBoost'),
      '#open' => TRUE,
    ];

    $form['get_topics_details']['get_topics_button'] = [
      '#type' => 'submit',
      '#value' => $this->t('Get Topics'),
      '#submit' => [$this, 'getTopicsSubmit'],
    ];

    // Add terms with checkboxes.
    $terms = [];
    $node = \Drupal::routeMatch()->getParameter('node');
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
    $form['get_topics_details']['terms'] = [
      '#type' => 'topicalboost_checkboxes',
      '#title' => $this->t('TopicalBoost'),
      '#node' => $node,
    ];

    return $form;
  }

  /**
   * Submit handler for the "Get Topics" button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function getTopicsSubmit(array $form, FormStateInterface $form_state) {
    // Add your logic here to handle the "Get Topics" button submission.
    // For example, you could retrieve the selected terms and perform some action.
    $selected_terms = array_filter($form_state->getValue('term'));
    // ...
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Implement the logic for submitting the form here.
  }

}
