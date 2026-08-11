<?php

/**
 * @file
 * Civic Services Directory content bootstrap (runs under `drush php:script`).
 *
 * Ships the product on first boot, idempotently:
 *   - "Service Categories" taxonomy vocabulary + terms
 *   - "Service" content type with its fields (category, agency, summary,
 *     full description, eligibility notes, required documents, official
 *     source URL, last verified date)
 *   - Six clearly-fictional demo service entries
 *   - "services_directory" view: the front-page listing grouped by category
 *   - Independent-service disclaimer block in the footer
 *   - Front page pointed at the view
 *
 * Every step checks existence first (NodeType::load(), View::load(),
 * loadByProperties(), ...), so re-running this script is a no-op that exits
 * 0 — it is safe on a fresh database and safe to re-run after redeploys.
 *
 * Branding: all demo content is fictional and carries the label
 * "[Demo entry — fictional, for template preview]". Entries link out to real
 * official portals, but this site is an independent directory — it never
 * imitates government styling.
 */

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;

const CIVIC_DISCLAIMER_TEXT = 'Independent information service — not a government website. Always confirm on official government portals.';
const CIVIC_DEMO_LABEL = '[Demo entry — fictional, for template preview]';
const CIVIC_VOCABULARY = 'service_categories';
const CIVIC_NODE_TYPE = 'service';
const CIVIC_VIEW_ID = 'services_directory';
const CIVIC_VIEW_PATH = 'services';
const CIVIC_BLOCK_ID = 'civicdisclaimer';

function civic_content_log(string $message): void {
  echo '[civic-services-directory] content bootstrap: ' . $message . "\n";
}

try {

  // ---------------------------------------------------------------------------
  // Modules required by the content model (all core). Install is idempotent
  // and also covers install profiles that ship without views/block_content.
  // ---------------------------------------------------------------------------
  $missing = array_values(array_filter(
    ['views', 'block_content', 'link', 'datetime', 'text'],
    static fn (string $module): bool => !\Drupal::moduleHandler()->moduleExists($module)
  ));
  if ($missing) {
    \Drupal::service('module_installer')->install($missing);
    civic_content_log('enabled core modules: ' . implode(', ', $missing));
  }

  // ---------------------------------------------------------------------------
  // 1. Taxonomy vocabulary "Service Categories"
  // ---------------------------------------------------------------------------
  $vocabulary = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load(CIVIC_VOCABULARY);
  if (!$vocabulary) {
    Vocabulary::create([
      'vid' => CIVIC_VOCABULARY,
      'name' => 'Service Categories',
      'description' => 'Categories used to group civic services in the directory.',
      'weight' => 0,
    ])->save();
    civic_content_log('created taxonomy vocabulary "Service Categories"');
  }

  $categories = [
    'Water & Utilities',
    'Health & Family Welfare',
    'Education & Scholarships',
    'Transport & Licenses',
    'Housing & Property',
    'Pensions & Social Security',
  ];
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $tids = [];
  foreach ($categories as $name) {
    $existing = $term_storage->loadByProperties(['vid' => CIVIC_VOCABULARY, 'name' => $name]);
    if ($existing) {
      $tids[$name] = (int) reset($existing)->id();
      continue;
    }
    $term = Term::create(['vid' => CIVIC_VOCABULARY, 'name' => $name]);
    $term->save();
    $tids[$name] = (int) $term->id();
    civic_content_log('created taxonomy term "' . $name . '"');
  }

  // ---------------------------------------------------------------------------
  // 2. Content type "Service"
  // ---------------------------------------------------------------------------
  if (!NodeType::load(CIVIC_NODE_TYPE)) {
    NodeType::create([
      'type' => CIVIC_NODE_TYPE,
      'name' => 'Service',
      'description' => 'A civic service entry: category, agency, summary, eligibility, required documents, official source link and last-verified date.',
      'help' => '',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
      'title_label' => 'Title',
      'preview_mode' => 1,
    ])->save();
    civic_content_log('created content type "Service"');
  }

  // ---------------------------------------------------------------------------
  // 3. Fields on the Service content type
  // ---------------------------------------------------------------------------
  $field_definitions = [
    'field_service_category' => [
      'label' => 'Category',
      'description' => 'The service category from the "Service Categories" vocabulary.',
      'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'taxonomy_term'], 'cardinality' => 1],
      'config' => [
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => ['service_categories' => 'service_categories'],
            'sort' => ['field' => 'name', 'direction' => 'ASC'],
            'auto_create' => 0,
            'auto_create_bundle' => '',
          ],
        ],
        'required' => TRUE,
      ],
    ],
    'field_agency' => [
      'label' => 'Agency/Department',
      'description' => 'The agency or department that provides the service.',
      'storage' => ['type' => 'text', 'settings' => ['max_length' => 255], 'cardinality' => 1],
      'config' => ['settings' => ['text_processing' => 0], 'required' => FALSE],
    ],
    'field_summary' => [
      'label' => 'Summary',
      'description' => 'A short plain-text summary shown in directory listings.',
      'storage' => ['type' => 'text_long', 'settings' => [], 'cardinality' => 1],
      'config' => ['settings' => ['text_processing' => 0], 'required' => TRUE],
    ],
    'field_full_description' => [
      'label' => 'Full description',
      'description' => 'The detailed, formatted service description.',
      'storage' => ['type' => 'text_long', 'settings' => [], 'cardinality' => 1],
      'config' => ['settings' => ['text_processing' => 1, 'allowed_formats' => []], 'required' => FALSE],
    ],
    'field_eligibility' => [
      'label' => 'Eligibility notes',
      'description' => 'Who can use the service and any conditions that apply.',
      'storage' => ['type' => 'text_long', 'settings' => [], 'cardinality' => 1],
      'config' => ['settings' => ['text_processing' => 0], 'required' => FALSE],
    ],
    'field_required_documents' => [
      'label' => 'Required documents',
      'description' => 'Documents usually required to apply for the service.',
      'storage' => ['type' => 'text_long', 'settings' => [], 'cardinality' => 1],
      'config' => ['settings' => ['text_processing' => 0], 'required' => FALSE],
    ],
    'field_official_source_url' => [
      'label' => 'Official source URL',
      'description' => 'Link to the official government portal where users can confirm the service. External links only.',
      'storage' => ['type' => 'link', 'settings' => ['max_length' => 255], 'cardinality' => 1],
      'config' => ['settings' => ['title' => 1, 'link_type' => 2], 'required' => TRUE],
    ],
    'field_last_verified' => [
      'label' => 'Last verified date',
      'description' => 'When this entry was last checked against the official source.',
      'storage' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'date'], 'cardinality' => 1],
      'config' => ['settings' => [], 'required' => FALSE],
    ],
  ];

  foreach ($field_definitions as $field_name => $def) {
    if (!FieldStorageConfig::load('node.' . $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $def['storage']['type'],
        'settings' => $def['storage']['settings'],
        'cardinality' => $def['storage']['cardinality'],
      ])->save();
    }
    if (!FieldConfig::load('node.service.' . $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => CIVIC_NODE_TYPE,
        'label' => $def['label'],
        'description' => $def['description'],
        'required' => $def['config']['required'],
        'settings' => $def['config']['settings'],
      ])->save();
    }
  }
  civic_content_log('fields ensured on content type "Service"');

  // ---------------------------------------------------------------------------
  // 4. Form + view displays for the Service content type
  // ---------------------------------------------------------------------------
  $form_display = EntityFormDisplay::load('node.service.default');
  if (!$form_display) {
    $form_display = EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => CIVIC_NODE_TYPE,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $form_display->save();
  }
  $form_components = [
    'field_service_category' => ['type' => 'options_select', 'settings' => [], 'weight' => 1, 'region' => 'content'],
    'field_agency' => ['type' => 'text_textfield', 'settings' => ['size' => 60, 'placeholder' => ''], 'weight' => 2, 'region' => 'content'],
    'field_summary' => ['type' => 'text_textarea', 'settings' => ['rows' => 3, 'placeholder' => ''], 'weight' => 3, 'region' => 'content'],
    'field_full_description' => ['type' => 'text_textarea', 'settings' => ['rows' => 9, 'placeholder' => ''], 'weight' => 4, 'region' => 'content'],
    'field_eligibility' => ['type' => 'text_textarea', 'settings' => ['rows' => 4, 'placeholder' => ''], 'weight' => 5, 'region' => 'content'],
    'field_required_documents' => ['type' => 'text_textarea', 'settings' => ['rows' => 4, 'placeholder' => ''], 'weight' => 6, 'region' => 'content'],
    'field_official_source_url' => ['type' => 'link_default', 'settings' => ['placeholder_url' => '', 'placeholder_title' => ''], 'weight' => 7, 'region' => 'content'],
    'field_last_verified' => ['type' => 'datetime_default', 'settings' => ['format_type' => 'html_date'], 'weight' => 8, 'region' => 'content'],
  ];
  foreach ($form_components as $field_name => $component) {
    if (!$form_display->getComponent($field_name)) {
      $form_display->setComponent($field_name, $component);
    }
  }
  $form_display->save();

  $view_display = EntityViewDisplay::load('node.service.default');
  if (!$view_display) {
    $view_display = EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => CIVIC_NODE_TYPE,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $view_display->save();
  }
  $view_components = [
    'field_service_category' => ['type' => 'entity_reference_label', 'settings' => ['link' => FALSE], 'label' => 'above', 'weight' => 1, 'region' => 'content'],
    'field_agency' => ['type' => 'string', 'settings' => ['link_to_entity' => FALSE], 'label' => 'above', 'weight' => 2, 'region' => 'content'],
    'field_summary' => ['type' => 'basic_string', 'settings' => [], 'label' => 'above', 'weight' => 3, 'region' => 'content'],
    'field_full_description' => ['type' => 'text_default', 'settings' => [], 'label' => 'above', 'weight' => 4, 'region' => 'content'],
    'field_eligibility' => ['type' => 'basic_string', 'settings' => [], 'label' => 'above', 'weight' => 5, 'region' => 'content'],
    'field_required_documents' => ['type' => 'basic_string', 'settings' => [], 'label' => 'above', 'weight' => 6, 'region' => 'content'],
    'field_official_source_url' => ['type' => 'link', 'settings' => ['trim_length' => 80, 'url_only' => FALSE, 'url_plain' => FALSE, 'rel' => 'nofollow', 'target' => '_blank'], 'label' => 'above', 'weight' => 7, 'region' => 'content'],
    'field_last_verified' => ['type' => 'datetime_default', 'settings' => ['format_type' => 'html_date'], 'label' => 'above', 'weight' => 8, 'region' => 'content'],
  ];
  foreach ($view_components as $field_name => $component) {
    if (!$view_display->getComponent($field_name)) {
      $view_display->setComponent($field_name, $component);
    }
  }
  $view_display->save();
  civic_content_log('form and view displays ensured for "Service"');

  // ---------------------------------------------------------------------------
  // 5. Demo services (fictional, labeled)
  // ---------------------------------------------------------------------------
  $demo_services = [
    [
      'title' => 'Example: Water connection application (fictional)',
      'category' => 'Water & Utilities',
      'agency' => 'Example: City Water Board (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How a household applies for a new water connection in this example city, including the documents typically requested.',
      'body' => CIVIC_DEMO_LABEL . '

This is a demo entry that ships with the Civic Services Directory template. It describes a made-up water connection application so you can see how a real service entry should look. The process shown here is illustrative only — always follow the official source link for the actual process in your state.

Example steps: submit the application form at the water board office or through its portal, attach proof of ownership of the property, pay the inspection fee, and receive the connection after the site inspection.',
      'eligibility' => 'Households with proof of residence in the service area. Rented properties need a No-Objection Certificate from the owner (example only).',
      'documents' => 'Proof of identity, proof of address, property ownership document, one passport-size photograph (example list).',
      'url' => 'https://up.gov.in/',
      'verified' => '2026-08-01',
    ],
    [
      'title' => 'Example: Senior citizen pension enrolment (fictional)',
      'category' => 'Pensions & Social Security',
      'agency' => 'Example: Department of Social Welfare (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How a senior citizen enrols for the example state pension scheme and what documents are required.',
      'body' => CIVIC_DEMO_LABEL . '

Demo entry only. It describes a fictional pension enrolment: residents above the example age threshold apply at the block development office or online, with proof of age and income. Eligibility rules and amounts shown here are illustrative — confirm the real scheme on the official source link.',
      'eligibility' => 'Residents above the example age threshold (60 years) with income below the illustrative limit (example criteria).',
      'documents' => 'Proof of age, bank account details, income certificate, recent photograph (example list).',
      'url' => 'https://www.india.gov.in/',
      'verified' => '2026-08-01',
    ],
    [
      'title' => 'Example: Student scholarship registration (fictional)',
      'category' => 'Education & Scholarships',
      'agency' => 'Example: State Education Board (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How students register for the example state scholarship and renew it every academic year.',
      'body' => CIVIC_DEMO_LABEL . '

Demo entry only. The example scholarship is open to students in recognised institutions with the illustrative minimum marks. Registration happens once per academic year through the board portal. All criteria shown here are fictional — check the official source link.',
      'eligibility' => 'Students in recognised institutions with the example minimum marks and a family income below the illustrative limit.',
      'documents' => 'Identity document, previous year marksheet, bank account details, institution enrolment code (example list).',
      'url' => 'https://www.maharashtra.gov.in/',
      'verified' => '2026-07-15',
    ],
    [
      'title' => 'Example: Driving licence renewal appointment (fictional)',
      'category' => 'Transport & Licenses',
      'agency' => 'Example: Regional Transport Office (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How to book an appointment to renew an expiring driving licence in this example state.',
      'body' => CIVIC_DEMO_LABEL . '

Demo entry only. In this fictional process, licence holders book a renewal slot on the transport department portal up to 30 days before expiry, carry the listed documents to the office, and clear the (illustrative) medical check. The real process may differ — follow the official source link.',
      'eligibility' => 'Licence holders whose licence expires within the example renewal window; applicants must be free of disqualifying conditions (illustrative).',
      'documents' => 'Existing licence, proof of address, medical certificate from an authorised doctor, fee receipt (example list).',
      'url' => 'https://www.tn.gov.in/',
      'verified' => '2026-07-15',
    ],
    [
      'title' => 'Example: Property tax payment (fictional)',
      'category' => 'Housing & Property',
      'agency' => 'Example: Municipal Corporation (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How property owners look up their tax assessment and pay it online in this example city.',
      'body' => CIVIC_DEMO_LABEL . '

Demo entry only. The fictional flow: owners search their property by assessment number on the corporation portal, verify the demand, pay by card or net banking, and download the receipt. Late-payment interest shown here is illustrative — confirm it on the official source link.',
      'eligibility' => 'Owners of assessed properties within the municipal limits (example).',
      'documents' => 'Assessment number or property ID, previous receipt if any (example list).',
      'url' => 'https://www.karnataka.gov.in/',
      'verified' => '2026-06-30',
    ],
    [
      'title' => 'Example: Village health camp registration (fictional)',
      'category' => 'Health & Family Welfare',
      'agency' => 'Example: District Health Office (fictional)',
      'summary' => CIVIC_DEMO_LABEL . ' How residents register for a free check-up at the example district health camp.',
      'body' => CIVIC_DEMO_LABEL . '

Demo entry only. Residents of the example block register at the panchayat office or through the health department helpline, then attend on the camp date with the listed documents. Services and dates are illustrative — see the official source link.',
      'eligibility' => 'Residents of the example block; priority for elderly people and children (illustrative).',
      'documents' => 'Identity document, previous prescriptions or reports if any (example list).',
      'url' => 'https://gujaratindia.gov.in/',
      'verified' => '2026-06-30',
    ],
  ];

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  foreach ($demo_services as $demo) {
    $existing = $node_storage->loadByProperties(['type' => CIVIC_NODE_TYPE, 'title' => $demo['title']]);
    if ($existing) {
      continue;
    }
    Node::create([
      'type' => CIVIC_NODE_TYPE,
      'title' => $demo['title'],
      'status' => 1,
      'langcode' => 'en',
      'field_service_category' => ['target_id' => $tids[$demo['category']]],
      'field_agency' => $demo['agency'],
      'field_summary' => $demo['summary'],
      'field_full_description' => ['value' => $demo['body'], 'format' => 'basic_html'],
      'field_eligibility' => $demo['eligibility'],
      'field_required_documents' => $demo['documents'],
      'field_official_source_url' => ['uri' => $demo['url'], 'title' => 'Official portal'],
      'field_last_verified' => ['value' => $demo['verified']],
    ])->save();
    civic_content_log('created demo service "' . $demo['title'] . '"');
  }

  // ---------------------------------------------------------------------------
  // 6. "services_directory" view (front-page listing grouped by category)
  // ---------------------------------------------------------------------------
  if (!View::load(CIVIC_VIEW_ID)) {
    View::create([
      'langcode' => 'en',
      'status' => TRUE,
      'id' => CIVIC_VIEW_ID,
      'label' => 'Services Directory',
      'module' => 'views',
      'description' => 'Lists civic services grouped by category.',
      'tag' => '',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Master',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'display_extenders' => [],
            'title' => 'Services Directory',
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'label' => '',
                'exclude' => FALSE,
                'alter' => ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE],
                'element_type' => '',
                'element_class' => '',
                'element_label_type' => '',
                'element_label_class' => '',
                'element_label_colon' => TRUE,
                'element_wrapper_type' => '',
                'element_wrapper_class' => '',
                'element_default_classes' => TRUE,
                'empty' => '',
                'hide_empty' => FALSE,
                'empty_zero' => FALSE,
                'hide_alter_empty' => TRUE,
                'click_sort_column' => 'value',
                'type' => 'string',
                'settings' => ['link_to_entity' => TRUE],
                'group_column' => 'value',
                'group_columns' => [],
                'group_rows' => TRUE,
                'delta_limit' => 0,
                'delta_offset' => 0,
                'delta_reversed' => FALSE,
                'delta_first_last' => FALSE,
                'multi_type' => 'separator',
                'separator' => ', ',
                'field_api_classes' => TRUE,
              ],
              'field_service_category' => [
                'id' => 'field_service_category',
                'table' => 'node__field_service_category',
                'field' => 'field_service_category',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'label' => '',
                'exclude' => TRUE,
                'alter' => ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE],
                'element_type' => '',
                'element_class' => '',
                'element_label_type' => '',
                'element_label_class' => '',
                'element_label_colon' => TRUE,
                'element_wrapper_type' => '',
                'element_wrapper_class' => '',
                'element_default_classes' => TRUE,
                'empty' => '',
                'hide_empty' => FALSE,
                'empty_zero' => FALSE,
                'hide_alter_empty' => TRUE,
                'click_sort_column' => 'target_id',
                'type' => 'entity_reference_label',
                'settings' => ['link' => FALSE],
                'group_column' => 'target_id',
                'group_columns' => [],
                'group_rows' => TRUE,
                'delta_limit' => 0,
                'delta_offset' => 0,
                'delta_reversed' => FALSE,
                'delta_first_last' => FALSE,
                'multi_type' => 'separator',
                'separator' => ', ',
                'field_api_classes' => TRUE,
              ],
              'field_summary' => [
                'id' => 'field_summary',
                'table' => 'node__field_summary',
                'field' => 'field_summary',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'label' => '',
                'exclude' => FALSE,
                'alter' => ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE],
                'element_type' => '',
                'element_class' => '',
                'element_label_type' => '',
                'element_label_class' => '',
                'element_label_colon' => TRUE,
                'element_wrapper_type' => '',
                'element_wrapper_class' => '',
                'element_default_classes' => TRUE,
                'empty' => '',
                'hide_empty' => FALSE,
                'empty_zero' => FALSE,
                'hide_alter_empty' => TRUE,
                'click_sort_column' => 'value',
                'type' => 'basic_string',
                'settings' => [],
                'group_column' => 'value',
                'group_columns' => [],
                'group_rows' => TRUE,
                'delta_limit' => 0,
                'delta_offset' => 0,
                'delta_reversed' => FALSE,
                'delta_first_last' => FALSE,
                'multi_type' => 'separator',
                'separator' => ', ',
                'field_api_classes' => TRUE,
              ],
              'field_official_source_url' => [
                'id' => 'field_official_source_url',
                'table' => 'node__field_official_source_url',
                'field' => 'field_official_source_url',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'label' => '',
                'exclude' => FALSE,
                'alter' => ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE],
                'element_type' => '',
                'element_class' => '',
                'element_label_type' => '',
                'element_label_class' => '',
                'element_label_colon' => TRUE,
                'element_wrapper_type' => '',
                'element_wrapper_class' => '',
                'element_default_classes' => TRUE,
                'empty' => '',
                'hide_empty' => FALSE,
                'empty_zero' => FALSE,
                'hide_alter_empty' => TRUE,
                'click_sort_column' => 'uri',
                'type' => 'link',
                'settings' => ['trim_length' => 80, 'url_only' => FALSE, 'url_plain' => FALSE, 'rel' => 'nofollow', 'target' => '_blank'],
                'group_column' => 'uri',
                'group_columns' => [],
                'group_rows' => TRUE,
                'delta_limit' => 0,
                'delta_offset' => 0,
                'delta_reversed' => FALSE,
                'delta_first_last' => FALSE,
                'multi_type' => 'separator',
                'separator' => ', ',
                'field_api_classes' => TRUE,
              ],
            ],
            'sorts' => [
              'field_service_category_target_id' => [
                'id' => 'field_service_category_target_id',
                'table' => 'node__field_service_category',
                'field' => 'field_service_category_target_id',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'order' => 'ASC',
                'exposed' => FALSE,
                'expose' => ['label' => ''],
              ],
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'order' => 'ASC',
                'exposed' => FALSE,
                'expose' => ['label' => ''],
              ],
            ],
            'filters' => [
              'status' => [
                'id' => 'status',
                'table' => 'node_field_data',
                'field' => 'status',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'entity_type' => 'node',
                'entity_field' => 'status',
                'plugin_id' => 'status',
                'operator' => '=',
                'value' => '1',
                'group' => 1,
                'exposed' => FALSE,
                'expose' => ['operator_id' => '', 'label' => '', 'description' => '', 'use_operator' => FALSE, 'operator' => '', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => '', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated']],
                'is_grouped' => FALSE,
                'group_info' => ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []],
              ],
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'relationship' => 'none',
                'group_type' => 'group',
                'admin_label' => '',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'plugin_id' => 'bundle',
                'operator' => 'in',
                'value' => ['service' => 'service'],
                'group' => 1,
                'exposed' => FALSE,
                'expose' => ['operator_id' => '', 'label' => '', 'description' => '', 'use_operator' => FALSE, 'operator' => '', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => '', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'], 'reduce' => FALSE],
                'is_grouped' => FALSE,
                'group_info' => ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []],
                'reduce' => FALSE,
              ],
            ],
            'style' => [
              'type' => 'default',
              'options' => [
                'grouping' => [
                  ['field' => 'field_service_category', 'rendered' => TRUE, 'rendered_strip' => FALSE],
                ],
              ],
            ],
            'row' => [
              'type' => 'fields',
              'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE],
            ],
            'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
            'cache' => ['type' => 'tag', 'options' => []],
            'exposed_form' => [
              'type' => 'basic',
              'options' => ['submit_button' => 'Apply', 'reset_button' => FALSE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc'],
            ],
            'access' => ['type' => 'none', 'options' => []],
            'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => FALSE, 'distinct' => FALSE, 'replica' => FALSE, 'query_comment' => '', 'query_tags' => []]],
            'use_ajax' => FALSE,
            'use_more' => FALSE,
            'use_more_always' => FALSE,
            'use_more_text' => 'more',
            'link_display' => 'page_1',
            'link_url' => '',
            'show_admin_links' => TRUE,
            'group_by' => FALSE,
            'css_class' => '',
            'relationships' => [],
            'arguments' => [],
            'header' => [],
            'footer' => [],
            'empty' => [],
          ],
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_title' => 'Page',
          'display_plugin' => 'page',
          'position' => 1,
          'display_options' => [
            'display_extenders' => [],
            'path' => CIVIC_VIEW_PATH,
            'display_description' => '',
            'menu' => ['type' => 'none', 'title' => '', 'description' => '', 'weight' => 0, 'context' => 0, 'menu_name' => 'main', 'parent' => ''],
            'show_admin_links' => TRUE,
          ],
        ],
      ],
    ])->save();
    civic_content_log('created view "services_directory" (front page /' . CIVIC_VIEW_PATH . ')');
  }

  // ---------------------------------------------------------------------------
  // 7. Disclaimer block in the footer
  // ---------------------------------------------------------------------------
  if (!BlockContentType::load('basic')) {
    // The 'basic' block type (with its body field) normally ships with the
    // block_content module; recreate it if a deployment removed it.
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic block'])->save();
    if (!FieldStorageConfig::load('block_content.body')) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'block_content',
        'type' => 'text_with_summary',
        'cardinality' => 1,
        'settings' => [],
      ])->save();
    }
    if (!FieldConfig::load('block_content.basic.body')) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'block_content',
        'bundle' => 'basic',
        'label' => 'Body',
        'settings' => ['display_summary' => FALSE],
      ])->save();
    }
  }

  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $existing_blocks = $block_storage->loadByProperties(['info' => 'Independent service disclaimer']);
  $disclaimer_content = $existing_blocks ? reset($existing_blocks) : NULL;
  if (!$disclaimer_content) {
    $disclaimer_content = BlockContent::create([
      'type' => 'basic',
      'info' => 'Independent service disclaimer',
    ]);
    $disclaimer_content->set('body', ['value' => CIVIC_DISCLAIMER_TEXT, 'format' => 'basic_html']);
    $disclaimer_content->save();
    civic_content_log('created disclaimer block content');
  }

  if (!Block::load(CIVIC_BLOCK_ID)) {
    $uuid = $disclaimer_content->uuid();
    $theme = \Drupal::config('system.theme')->get('default') ?: 'olivero';
    Block::create([
      'id' => CIVIC_BLOCK_ID,
      'theme' => $theme,
      'region' => 'footer_bottom',
      'weight' => 0,
      'status' => TRUE,
      'visibility' => [],
      'plugin' => 'block_content:' . $uuid,
      'settings' => [
        'id' => 'block_content:' . $uuid,
        'label' => 'Independent service disclaimer',
        'label_display' => '0',
        'provider' => 'block_content',
        'status' => TRUE,
        'info' => '',
        'view_mode' => 'full',
      ],
    ])->save();
    civic_content_log('placed disclaimer block in the footer (region footer_bottom)');
  }

  // ---------------------------------------------------------------------------
  // 8. Front page = the services directory view
  // ---------------------------------------------------------------------------
  $site = \Drupal::configFactory()->getEditable('system.site');
  if ($site->get('page.front') !== '/' . CIVIC_VIEW_PATH) {
    $site->set('page.front', '/' . CIVIC_VIEW_PATH)->save();
    civic_content_log('set front page to /' . CIVIC_VIEW_PATH);
  }

  // ---------------------------------------------------------------------------
  // 9. Rebuild routes + caches so the new view path and blocks render.
  // ---------------------------------------------------------------------------
  drupal_flush_all_caches();
  civic_content_log('content bootstrap complete (idempotent)');

}
catch (\Throwable $e) {
  fwrite(
    STDERR,
    "[civic-services-directory] content bootstrap FAILED: " . $e->getMessage() . "\n" .
    $e->getTraceAsString() . "\n"
  );
  exit(1);
}
