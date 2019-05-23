<?php

/**
 * CiviCRM Caldera Forms Contact Processor Class.
 *
 * @since 0.2
 */
class CiviCRM_Caldera_Forms_Contact_Processor {

	/**
	 * Plugin reference.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var object $plugin The plugin instance
	 */
	public $plugin;

	/**
	 * Contact link.
	 *
	 * @since 0.4.4
	 * @access protected
	 * @var string $contact_link The contact link
	 */
	protected $contact_link;

	/**
	 * The processor key.
	 *
	 * @since 0.2
	 * @access public
	 * @var str $key_name The processor key
	 */
	public $key_name = 'civicrm_contact';

	/**
	 * Entities that are allowed to pre-render CiviCRM data, excluding the Contact itself ie. 'civicrm_contact'.
	 *
	 * @since 0.3
	 * @access private
	 * @var array $entities_to_prerender
	 */
	private $entities_to_prerender = [ 'process_address', 'process_phone', 'process_email', 'process_website', 'process_im' ];

	/**
	 * Fields to ignore while prepopulating
	 *
	 * @since 0.4
	 * @access public
	 * @var array $fields_to_ignore Fields to ignore
	 */
	public $fields_to_ignore = [ 'auto_pop', 'contact_type', 'contact_sub_type', 'contact_link', 'dedupe_rule', 'location_type_id', 'website_type_id' ];

	/**
	 * Initialises this object.
	 *
	 * @since 0.2
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		// register this processor
		add_filter( 'caldera_forms_get_form_processors', [ $this, 'register_processor' ] );
		// filter form before rendering
		add_filter( 'caldera_forms_render_get_form', [ $this, 'pre_render' ] );

	}

	/**
	 * Adds this processor to Caldera Forms.
	 *
	 * @since 0.2
	 *
	 * @uses 'caldera_forms_get_form_processors' filter
	 *
	 * @param array $processors The existing processors
	 * @return array $processors The modified processors
	 */
	public function register_processor( $processors ) {

		$processors[$this->key_name] = [
			'name' =>  __( 'CiviCRM Contact', 'caldera-forms-civicrm' ),
			'description' =>  __( 'Create CiviCRM contact', 'caldera-forms-civicrm' ),
			'author' =>  'Andrei Mondoc',
			'template' =>  CF_CIVICRM_INTEGRATION_PATH . 'processors/contact/contact_config.php',
			'pre_processor' =>  [ $this, 'pre_processor' ],
		];

		return $processors;

	}

	/**
	 * Form processor callback.
	 *
	 * @since 0.2
	 *
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function pre_processor( $config, $form, $processid ) {

		// globalised transient object
		global $transdata;
		// cfc transient object
		$transient = $this->plugin->transient->get();
		$this->contact_link = 'cid_' . $config['contact_link'];

		// Get form values
		$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_contact' );

		if ( ! empty( $form_values['civicrm_contact'] ) ) {

			// Set Contact type and sub-type from prcessor config
			$form_values['civicrm_contact']['contact_type'] = $config['civicrm_contact']['contact_type'];
			$form_values['civicrm_contact']['contact_sub_type'] = $config['civicrm_contact']['contact_sub_type'];

			// Use 'Process email' field for deduping if primary email is not set and 'Process email' is enabled
			if ( empty( $config['civicrm_contact']['email'] ) && isset( $config['enabled_entities']['process_email'] ) ) {
				foreach ( $config['civicrm_email'] as $key => $field_id ) {
					if ( $key === 'email' ) {
						$email_mapped_field = Caldera_Forms::get_field_by_slug(str_replace( '%', '', $field_id ), $form );
						$form_values['civicrm_contact'][$key] = Caldera_Forms::get_field_data( $email_mapped_field['ID'], $form );
					}
				}
			}

			// Indexed array containing the Email processors
			$civicrm_email_pr = Caldera_Forms::get_processor_by_type( 'civicrm_email', $form );
			if ( $civicrm_email_pr ) {
				foreach ( $civicrm_email_pr as $key => $value ) {
					if ( ! is_int( $key ) ) {
						unset( $civicrm_email_pr[$key] );
					}
				}
			}

			// FIXME Add Email processor option to set Defaul email for deduping?
			// Override Contact processor email address with first Email processor
			if ( $civicrm_email_pr && empty( $config['civicrm_contact']['email'] ) ) {
				foreach ( $civicrm_email_pr[0]['config'] as $field => $value ) {
					if ( $field === 'email' ) {
						$form_values[$field] = $transdata['data'][$value];
					}
				}
			}

			// FIXME
			// the prepopulated Contact and submitter of the form is always Contact with Link 1
			// the Contact with Link 1 should always be first in the processors array
			$first_contact_processor = array_reverse( $form['processors'] );
			$first_contact_processor = array_pop( $first_contact_processor );

			if ( is_array( $first_contact_processor ) && $first_contact_processor['ID'] == $config['processor_id'] && isset( $config['auto_pop'] ) ) {
				// logged in contact
				$contact = $this->plugin->helper->get_current_contact();
				// if not logged in, do dedupe
				$contact_id = $contact ? $contact['contact_id'] : $this->plugin->helper->civi_contact_dedupe(
					$form_values['civicrm_contact'], // contact data
					$config['civicrm_contact']['contact_type'], // contact type
					$config['civicrm_contact']['dedupe_rule'] // dedupe rule
				);
			} else {
				// dedupe contact
				$contact_id = $this->plugin->helper->civi_contact_dedupe(
					$form_values['civicrm_contact'], // contact data
					$config['civicrm_contact']['contact_type'], // contact type
					$config['civicrm_contact']['dedupe_rule'] // dedupe rule
				);
			}

			// Stop the processor if user want not to update the existing contact
			if (isset( $config['prevent_update'] ) && $contact_id != 0) {
				return [ 'note' => "This contact information is duplicated.", 'type' => 'error' ];
			}

			if ( is_array( $first_contact_processor ) && $first_contact_processor['ID'] != $config['processor_id'] && isset( $config['auto_pop_by_relationship'] ) && $config['auto_pop_by_relationship'] == 1 && isset( $config['auto_populate_relationship_type'] ) && $config['auto_populate_relationship_type'] != '' ) {
				// get related contact data
				$contact = $this->getRelatedContactOfLoggedInUser( $config['auto_populate_relationship_type'] );
				if ( $contact != NULL ) {
					$contact_id = $contact['id'];
				}
			}

			// pass first contact or deduped contact
			$form_values['civicrm_contact']['contact_id'] = $contact_id;

			// Prevent API overriding exisiting contact_sub_type
			// If we have a contact_id, get the contact and push the sub-type set in Contact config
			if( $form_values['civicrm_contact']['contact_id'] ){
				$existing_contact = $this->plugin->helper->get_civi_contact( $form_values['civicrm_contact']['contact_id'] );
				if ( is_array( $existing_contact['contact_sub_type'] ) ) {
					if ( ! empty( $config['civicrm_contact']['contact_sub_type'] ) && !in_array( $config['civicrm_contact']['contact_sub_type'], $existing_contact['contact_sub_type'])) {
						array_push( $existing_contact['contact_sub_type'], $config['civicrm_contact']['contact_sub_type'] );
					}
					$form_values['civicrm_contact']['contact_sub_type'] = $existing_contact['contact_sub_type'];
				}
			}

			// handle contact image url
			if ( ! empty( $config['civicrm_contact']['image_URL'] ) && ! empty( $form_values['civicrm_contact']['image_URL'] ) ) {
				try {
					$file = civicrm_api3( 'File', 'getsingle', [ 'id' => $form_values['civicrm_contact']['image_URL'] ] );
				} catch ( CiviCRM_API3_Exception $e ) {

				}

				if ( is_array( $file ) && ! $file['is_error'] )
					$form_values['civicrm_contact']['image_URL'] = CRM_Utils_System::url( 'civicrm/contact/imagefile', ['photo' => $file['uri']], true );
			}

			// contact reference field for organization maps to an array [ 'organization_name' => <name>, 'employer_id' => <id> ]
			if ( ! empty( $form_values['civicrm_contact']['current_employer'] ) && is_array( $form_values['civicrm_contact']['current_employer'] ) ) {
				$org = $form_values['civicrm_contact']['current_employer'];
				$form_values['civicrm_contact']['current_employer'] = $org['organization_name'];
				// need to be set in case of duplicate orgs with same name
				$form_values['civicrm_contact']['employer_id'] = $org['employer_id'];
			}

			try {
				$create_contact = civicrm_api3( 'Contact', 'create', $form_values['civicrm_contact'] );
			} catch ( CiviCRM_API3_Exception $e ) {
				$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
				return [ 'note' => $error, 'type' => 'error' ];
			}

			// FIXME
			// Civi's API doesn't update the primary email address doing a Contact.create,
			// so do it manually
			// check current contact is logged in and if primary email is set
			if ( isset( $config['civicrm_contact']['email'] ) && isset( $contact ) && $create_contact['id'] == $contact['contact_id'] ) {
				// update if email has changed
				if ( $contact['email'] != $form_values['civicrm_contact']['email'] ) {
					try {
						$new_email = civicrm_api3( 'Email', 'create', [
							'id' => $contact['email_id'],
							'email' => $form_values['civicrm_contact']['email'],
							'is_primary' => 1,
						] );
					} catch ( CiviCRM_API3_Exception $e ) {
						$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
						return [ 'note' => $error, 'type' => 'error' ];
					}
				}
			}

			// Store $cid
			$transient->contacts->{$this->contact_link} = $create_contact['id'];
			$this->plugin->transient->save( $transient->ID, $transient );

			// Add contact to Domain group if set, if not set 'domain_group_id' should be 0
			$domain_group_id = $this->plugin->helper->get_civicrm_settings( 'domain_group_id' );
			if( $domain_group_id ){
				try {
					$group_contact = civicrm_api3( 'GroupContact', 'create', [
						'sequential' => 1,
						'group_id' => $domain_group_id,
						'contact_id' => $create_contact['id'],
					] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}

			/**
			 * Handle File fields for attachments.
			 * @since 0.4.2
			 */
			foreach ( $config['civicrm_contact'] as $c_field => $val ) {
				if ( ! empty( $val ) && substr( $c_field, 0, 7 ) === 'custom_' ) {
					// caldera forms field config
					$cf_field = Caldera_Forms::get_field_by_slug(str_replace( '%', '', $val ), $form );
					// files
					$file_fields = $this->plugin->fields->field_objects['civicrm_file']->file_fields;

					if ( ! empty( $file_fields[$cf_field['ID']]['files'] ) ) {
						// custom field id
						$c_field_id = preg_replace('/\D/', '', $c_field);
						// custom field
						$field_type = civicrm_api3( 'CustomField', 'getsingle', [
							'id' => $c_field_id,
							'return' => [ 'custom_group_id.table_name', 'custom_group_id', 'data_type' ],
						] );

						if ( $field_type['data_type'] == 'File' ) {
							foreach ( $file_fields[$cf_field['ID']]['files'] as $file_id => $file ) {
								$this->plugin->helper->create_civicrm_entity_file(
									$field_type['custom_group_id.table_name'],
									$transient->contacts->{$this->contact_link},
									$file_id
								);
							}
						}
					}
				}
			}

			/**
			 * Process enabled entities.
			 * @since 0.3
			 */
			if ( isset( $config['enabled_entities'] ) ){
				foreach ( $config['enabled_entities'] as $entity => $value ) {
					if( isset( $entity ) && $value == 1 ){
						$this->$entity( $config, $form, $transient, $form_values );
					}
				}
			}

		}

	}

	/**
	 * Process Address.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_address( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			try {
				$address = civicrm_api3( 'Address', 'getsingle', [
					'sequential' => 1,
					'contact_id' => $transient->contacts->{$this->contact_link},
					'location_type_id' => $config['civicrm_address']['location_type_id'],
				] );
			} catch ( CiviCRM_API3_Exception $e ) {
				// Ignore if none found
			}

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_address' );

			if( ! empty( $form_values['civicrm_address'] ) ) {
				$form_values['civicrm_address']['contact_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor

				// Pass address ID if we got one
				if ( isset( $address ) && is_array( $address ) ) {
					$form_values['civicrm_address']['id'] = $address['id']; // Address ID
				} else {
					$form_values['civicrm_address']['location_type_id'] = $config['civicrm_address']['location_type_id'];
				}

				// FIXME
				// Concatenate DATE + TIME
				// $form_values['activity_date_time'] = $form_values['activity_date_time'];

				if ( isset( $config['civicrm_address']['is_override'] ) ) {
					foreach ( $this->plugin->processors->processors['address']->fields as $key => $field ) {
						if ( ! isset( $form_values['civicrm_address'][$field] ) )
							$form_values['civicrm_address'][$field] = '';
					}
				}

				try {
					$create_address = civicrm_api3( 'Address', 'create', $form_values['civicrm_address'] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}
	}

	/**
	 * Process Phone.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_phone( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			try {

				$phone = civicrm_api3( 'Phone', 'getsingle', [
					'contact_id' => $transient->contacts->{$this->contact_link},
					'location_type_id' => $config['civicrm_phone']['location_type_id'],
					'phone_type_id' => $config['civicrm_phone']['phone_type_id'],
				] );

			} catch ( CiviCRM_API3_Exception $e ) {
				// Ignore if none found
			}

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_phone' );

			if( ! empty( $form_values['civicrm_phone'] ) ) {
				$form_values['civicrm_phone']['contact_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor
				$form_values['civicrm_phone']['location_type_id'] = $config['civicrm_phone']['location_type_id'];
				$form_values['civicrm_phone']['phone_type_id'] = $config['civicrm_phone']['phone_type_id'];

				// Pass Phone ID if we got one
				if ( isset( $phone ) && is_array( $phone ) )
					$form_values['civicrm_phone']['id'] = $phone['id']; // Phone ID

				if ( ! empty( $form_values['civicrm_phone']['phone'] ) && strlen( $form_values['civicrm_phone']['phone'] ) > 4 ) {
					try {
						$create_phone = civicrm_api3( 'Phone', 'create', $form_values['civicrm_phone'] );
					} catch ( CiviCRM_API3_Exception $e ) {
						$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
						return [ 'note' => $error, 'type' => 'error' ];
					}
				}
			}
		}
	}

	/**
	 * Process Note.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_note( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_note' );

			if( ! empty( $form_values['civicrm_note'] ) ) {
				$form_values['civicrm_note']['entity_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor

				// Add Note to contact
				try {
					$note = civicrm_api3( 'Note', 'create', $form_values['civicrm_note'] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}
	}

	/**
	 * Process Email.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_email( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			try {

				$email = civicrm_api3( 'Email', 'getsingle', [
					'sequential' => 1,
					'contact_id' => $transient->contacts->{$this->contact_link},
					'location_type_id' => $config['civicrm_email']['location_type_id'],
				] );

			} catch ( CiviCRM_API3_Exception $e ) {
				// Ignore if none found
			}

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_email' );

			if ( ! empty( $form_values['civicrm_email'] ) ) {
				$form_values['civicrm_email']['contact_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor

				// Pass Email ID if we got one
				if ( isset( $email ) && is_array( $email ) ) {
					$form_values['civicrm_email']['id'] = $email['id']; // Email ID
				} else {
					$form_values['civicrm_email']['location_type_id'] = $config['civicrm_email']['location_type_id'];
				}

				try {
					$create_email = civicrm_api3( 'Email', 'create', $form_values['civicrm_email'] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}
	}

	/**
	 * Process Website.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_website( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			try {

				$website = civicrm_api3( 'Website', 'getsingle', [
					'sequential' => 1,
					'contact_id' => $transient->contacts->{$this->contact_link},
					'website_type_id' => $config['civicrm_website']['website_type_id'],
				] );

			} catch ( CiviCRM_API3_Exception $e ) {
				// Ignore if none found
			}

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_website' );

			if( ! empty( $form_values['civicrm_website'] ) ) {
				$form_values['civicrm_website']['contact_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor

				// Pass Website ID if we got one
				if ( isset( $website ) && is_array( $website ) ) {
					$form_values['civicrm_website']['id'] = $website['id']; // Website ID
				} else {
					$form_values['civicrm_website']['website_type_id'] = $config['civicrm_website']['website_type_id'];
				}

				try {
					$create_email = civicrm_api3( 'Website', 'create', $form_values['civicrm_website'] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}
	}

	/**
	 * Process Im.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_im( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {

			try {

				$im = civicrm_api3( 'Im', 'getsingle', [
					'sequential' => 1,
					'contact_id' => $transient->contacts->{$this->contact_link},
					'location_type_id' => $config['civicrm_im']['location_type_id'],
				] );

			} catch ( CiviCRM_API3_Exception $e ) {
				// Ignore if none found
			}

			// Get form values
			$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values, 'civicrm_im' );

			if( ! empty( $form_values['civicrm_im'] ) ){
				$form_values['civicrm_im']['contact_id'] = $transient->contacts->{$this->contact_link}; // Contact ID set in Contact Processor

				// Pass Im ID if we got one
				if ( isset( $im ) && is_array( $im ) ) {
					$form_values['civicrm_im']['id'] = $im['id']; // Im ID
				} else {
					$form_values['civicrm_im']['location_type_id'] = $config['civicrm_im']['location_type_id']; // IM Location type set in Processor config
				}

				try {
					$create_im = civicrm_api3( 'Im', 'create', $form_values['civicrm_im'] );
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}
	}

	/**
	 * Process Group.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_group( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {
			try {
				$result = civicrm_api3( 'GroupContact', 'create', [
					'sequential' => 1,
					'group_id' => $config['civicrm_group']['contact_group'], // Group ID from processor config
					'contact_id' => $transient->contacts->{$this->contact_link}, // Contact ID set in Contact Processor
				] );
			} catch ( CiviCRM_API3_Exception $e ) {
				$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
				return [ 'note' => $error, 'type' => 'error' ];
			}
		}

	}

	/**
	 * Process Tag.
	 *
	 * @since 0.3
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param array $transient The globalised transient object
	 * @param array $form_values The field values beeing submitted
	 */
	public function process_tag( $config, $form, $transient, &$form_values ){

		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {
			foreach ( $config['civicrm_tag'] as $key => $value ) {
				if ( stristr( $key, 'entity_tag' ) != false ) {
					try {
						$tag = civicrm_api3( 'Tag', 'getsingle', [
							'sequential' => 1,
							'id' => $value,
							'api.EntityTag.create' => [
								'entity_id' => $transient->contacts->{$this->contact_link},
								'entity_table' => 'civicrm_contact',
								'tag_id' => '$value.id',
							],
						] );
					} catch ( CiviCRM_API3_Exception $e ) {
						$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
						return [ 'note' => $error, 'type' => 'error' ];
					}
				}
			}
		}

	}

	/**
	 * Autopopulates Form with Civi data
	 *
	 * @uses 'caldera_forms_render_get_form' filter
	 *
	 * @since 0.2
	 *
	 * @param array $form The form
	 * @return array $form The modified form
	 */
	public function pre_render( $form ) {

		// continue as normal if form has no processors
		if ( empty( $form['processors'] ) ) return $form;

		// cfc transient object
		$transient = $this->plugin->transient->get();

		// Indexed array containing the Contact processors
		$civicrm_contact_pr = Caldera_Forms::get_processor_by_type( 'civicrm_contact', $form );
		if ( $civicrm_contact_pr ) {
			foreach ( $civicrm_contact_pr as $key => $value ) {
				if ( ! is_int( $key ) ) {
					unset( $civicrm_contact_pr[$key] );
				}
			}
		}

		foreach ( $form['processors'] as $processor => $pr_id ) {
			// this would be a good place to set form defaults based on Civi data

			// get and set 'contact_default_language' if the contact doesn't have one set
			if ( ! empty( $pr_id['config']['civicrm_contact']['preferred_language'] ) ) {
				$preferred_language = Caldera_Forms::get_field_by_slug(str_replace( '%', '', $pr_id['config']['civicrm_contact']['preferred_language'] ), $form );
				if ( empty( $preferred_language['config']['default'] ) ) {
					$form['fields'][$preferred_language['ID']]['config']['default'] = CRM_Core_Config::singleton()->lcMessages;
				}
			}

			if( $pr_id['type'] == $this->key_name && isset( $pr_id['runtimes'] ) ){

				if ( isset( $pr_id['config']['auto_pop'] ) && $pr_id['config']['auto_pop'] == 1 && $civicrm_contact_pr[0]['ID'] == $pr_id['ID'] )
					// get contact data
					$contact = $this->plugin->helper->current_contact_data_get();

				if ( isset( $pr_id['config']['auto_pop_by_relationship'] ) && $pr_id['config']['auto_pop_by_relationship'] == 1 && isset( $pr_id['config']['auto_populate_relationship_type'] ) && $pr_id['config']['auto_populate_relationship_type'] != '' && $civicrm_contact_pr[0]['ID'] != $pr_id['ID'] ) {
				  // get related contact data
          $contact = $this->getRelatedContactOfLoggedInUser($pr_id['config']['auto_populate_relationship_type']);
        }

				// Map CiviCRM contact data to form defaults
				if ( isset( $contact ) && is_array( $contact ) ) {
					// contact link reference
					$contact_link = $pr_id['contact_link'] = 'cid_'.$pr_id['config']['contact_link'];
					// set contact link in transient
					$transient->contacts->{$contact_link} = $contact['contact_id'];
					$this->plugin->transient->save( $transient->ID, $transient );

					$form = $this->plugin->helper->map_fields_to_prerender(
						$pr_id['config'],
						$form,
						$this->fields_to_ignore,
						$contact,
						'civicrm_contact'
					);

					// FIXME
					// add filter hook here for this kind of things

					// get and set 'contact_default_language' if the contact doesn't have one set
					if ( ! empty( $pr_id['config']['civicrm_contact']['preferred_language'] ) ) {
						$preferred_language = Caldera_Forms::get_field_by_slug(str_replace( '%', '', $pr_id['config']['civicrm_contact']['preferred_language'] ), $form );
						if ( empty( $preferred_language['config']['default'] ) ) {
							$form['fields'][$preferred_language['ID']]['config']['default'] = CRM_Core_Config::singleton()->lcMessages;
						}
					}
				}

				// Clear Contact data
				unset( $contact );

				// Map CiviCRM data for the enabled entities/processors
				if ( isset( $pr_id['config']['enabled_entities'] ) ){
					foreach ( $pr_id['config']['enabled_entities'] as $entity => $value) {
						if( isset( $entity ) && in_array( $entity, $this->entities_to_prerender ) ){
							$pre_render_entity = str_replace( 'process_', 'pre_render_', $entity );
							$this->$pre_render_entity( $pr_id, $transient, $form, $this->fields_to_ignore );
						}
					}
				}

			}
		}

		return $form;
	}

  /**
   * Find related contact of logged in user by given relationship type id.
   * @param $relationshipTypeId
   * @return |null
   * @throws CiviCRM_API3_Exception
   */
	private function getRelatedContactOfLoggedInUser($relationshipTypeId) {
    $contact = $this->plugin->helper->current_contact_data_get();
    if ( isset( $contact ) && is_array( $contact ) ) {
      $contactId = $contact['contact_id'];

      $relationship = civicrm_api3('Relationship', 'get', [
        'sequential'           => 1,
        'contact_id_a'         => $contactId,
        'contact_id_b'         => $contactId,
        'relationship_type_id' => $relationshipTypeId,
        'options'              => ['or' => [["contact_id_a", "contact_id_b"]]],
      ]);

      if (count($relationship) > 0) {
        $relationship = $relationship['values'][0];
        $otherContactId = ($relationship['contact_id_a'] == $contactId) ? $relationship['contact_id_b'] : $relationship['contact_id_a'];
        $contact = $this->plugin->helper->get_civi_contact( $otherContactId );
        return $contact;
      }
    }
    return NULL;
  }

	/**
	 * Pre-render Address data.
	 *
	 * @since 0.3
	 *
	 * @param array $pr_id The processor
	 * @param array $transient The globalised transient object
	 * @param array $form The Form object
	 * @param array $ignore_fields Fields to ignore when mapping Civi data into the form
	 */
	public function pre_render_address( $pr_id, $transient, &$form, $ignore_fields ) {
		if( isset( $pr_id['config']['enabled_entities']['process_address'] ) ){
			if ( isset( $transient->contacts->{$pr_id['contact_link']} ) ) {
				try {

					$contact_address = civicrm_api3( 'Address', 'getsingle', [
						'sequential' => 1,
						'contact_id' => $transient->contacts->{$pr_id['contact_link']},
						'location_type_id' => $pr_id['config']['civicrm_address']['location_type_id'],
					] );

				} catch ( CiviCRM_API3_Exception $e ) {
					// Ignore if we have more than one address with same location type
				}
			}

			if ( isset( $contact_address ) && ! isset( $contact_address['count'] ) ) {
				$form = $this->plugin->helper->map_fields_to_prerender(
					$pr_id['config'],
					$form,
					$ignore_fields,
					$contact_address,
					'civicrm_address'
				);
			}

			// Clear Address data
			unset( $contact_address );

		}
	}

	/**
	 * Pre-render Phone data.
	 *
	 * @since 0.3
	 *
	 * @param array $pr_id The processor
	 * @param array $transient The globalised transient object
	 * @param array $form The Form object
	 * @param array $ignore_fields Fields to ignore when mapping Civi data into the form
	 */
	public function pre_render_phone( $pr_id, $transient, &$form, $ignore_fields ){

		if( isset( $pr_id['config']['enabled_entities']['process_phone'] ) ){
			if ( isset( $transient->contacts->{$pr_id['contact_link']} ) ) {
				try {

					$contact_phone = civicrm_api3( 'Phone', 'getsingle', [
						'sequential' => 1,
						'contact_id' => $transient->contacts->{$pr_id['contact_link']},
						'location_type_id' => $pr_id['config']['civicrm_phone']['location_type_id'],
						'phone_type_id' => $pr_id['config']['civicrm_phone']['phone_type_id'],
					] );

				} catch ( CiviCRM_API3_Exception $e ) {
					// Ignore if we have more than one phone with same location type or none
				}
			}

			if ( isset( $contact_phone ) && ! isset( $contact_phone['count'] ) ) {
				$form = $this->plugin->helper->map_fields_to_prerender(
					$pr_id['config'],
					$form,
					$ignore_fields,
					$contact_phone,
					'civicrm_phone'
				);
			}

			// Clear Phone data
			unset( $contact_phone );
		}
	}

	/**
	 * Pre-render Email data.
	 *
	 * @since 0.3
	 *
	 * @param array $pr_id The processor
	 * @param array $transient The globalised transient object
	 * @param array $form The Form object
	 * @param array $ignore_fields Fields to ignore when mapping Civi data into the form
	 */
	public function pre_render_email( $pr_id, $transient, &$form, $ignore_fields ){

		if( isset( $pr_id['config']['enabled_entities']['process_email'] ) ){
			if ( isset( $transient->contacts->{$pr_id['contact_link']} ) ) {
				try {

					$contact_email = civicrm_api3( 'Email', 'getsingle', [
						'sequential' => 1,
						'contact_id' => $transient->contacts->{$pr_id['contact_link']},
						'location_type_id' => $pr_id['config']['civicrm_email']['location_type_id'],
					] );

				} catch ( CiviCRM_API3_Exception $e ) {
					// Ignore if we have more than one email with same location type or none
				}
			}

			if ( isset( $contact_email ) && ! isset( $contact_email['count'] ) ) {
				$form = $this->plugin->helper->map_fields_to_prerender(
					$pr_id['config'],
					$form,
					$ignore_fields,
					$contact_email,
					'civicrm_email'
				);
			}

			// Clear Email data
			unset( $contact_email );
		}
	}

	/**
	 * Pre-render Website data.
	 *
	 * @since 0.3
	 *
	 * @param array $pr_id The processor
	 * @param array $transient The globalised transient object
	 * @param array $form The Form object
	 * @param array $ignore_fields Fields to ignore when mapping Civi data into the form
	 */
	public function pre_render_website( $pr_id, $transient, &$form, $ignore_fields ){

		if( isset( $pr_id['config']['enabled_entities']['process_website'] ) ){
			if ( isset( $transient->contacts->{$pr_id['contact_link']} ) ) {
				try {

					$contact_website = civicrm_api3( 'Website', 'getsingle', [
						'sequential' => 1,
						'contact_id' => $transient->contacts->{$pr_id['contact_link']},
						'website_type_id' => $pr_id['config']['civicrm_website']['website_type_id'],
					] );

				} catch ( CiviCRM_API3_Exception $e ) {
					// Ignore if we have more than one website with same location type or none
				}
			}

			if ( isset( $contact_website ) && ! isset( $contact_website['count'] ) ) {
				$form = $this->plugin->helper->map_fields_to_prerender(
					$pr_id['config'],
					$form,
					$ignore_fields,
					$contact_website,
					'civicrm_website'
				);
			}

			// Clear Website data
			unset( $contact_website );
		}
	}

	/**
	 * Pre-render Im data.
	 *
	 * @since 0.3
	 *
	 * @param array $pr_id The processor
	 * @param array $transient The globalised transient object
	 * @param array $form The Form object
	 * @param array $ignore_fields Fields to ignore when mapping Civi data into the form
	 */
	public function pre_render_im( $pr_id, $transient, &$form, $ignore_fields ){

		if( isset( $pr_id['config']['enabled_entities']['process_im'] ) ){
			if ( isset( $transient->contacts->{$pr_id['contact_link']} ) ) {
				try {

					$contact_im = civicrm_api3( 'Im', 'getsingle', [
						'sequential' => 1,
						'contact_id' => $transient->contacts->{$pr_id['contact_link']},
						'location_type_id' => $pr_id['config']['civicrm_im']['location_type_id'],
					] );

				} catch ( CiviCRM_API3_Exception $e ) {
					// Ignore if we have more than one Im with same location type or none
				}
			}

			if ( isset( $contact_im ) && ! isset( $contact_im['count'] ) ) {
				$form = $this->plugin->helper->map_fields_to_prerender(
					$pr_id['config'],
					$form,
					$ignore_fields,
					$contact_im,
					'civicrm_im'
				);
			}

			// Clear Im data
			unset( $contact_im );
		}
	}

}
