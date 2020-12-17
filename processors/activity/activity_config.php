<?php

$ignore_fields = [ 'target_contact_id', 'source_contact_id', 'assignee_contact_id', 'source_record_id', 'contact_id' ];

$activities = civicrm_api3( 'Activity', 'getoptions', [
	'sequential' => 1,
	'field' => 'activity_type_id',
] );

$activity_status = civicrm_api3( 'Activity', 'getoptions', [
	'sequential' => 1,
	'field' => 'status_id',
] );

$campaign_id = civicrm_api3( 'Campaign', 'get', [
	'sequential' => 1,
	'is_active' => 1,
	'options' => [ 'limit' => 0 ],
] );

$activityFieldsResult = civicrm_api3( 'Activity', 'getfields', [
	'sequential' => 1,
] );

$activityFields = [];
foreach ( $activityFieldsResult['values'] as $key => $value ) {
	if ( ! in_array( $value['name'], caldera_forms_civicrm()->helper->activity_fields ) ) {
		$activityFields[$value['name']] = $value['title'];
	}
}

?>

<h2><?php _e( 'Contact Link', 'cf-civicrm' ); ?></h2>
<div class="caldera-config-group">
	<label><?php _e( 'Link to', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field">
		<?php caldera_forms_civicrm()->helper->contact_link_field(); ?>
		<p><?php _e( 'Select which contact you want to link this processor to.', 'cf-civicrm' ); ?></p>
	</div>
</div>

<hr style="clear: both;" />

<!-- Activity Type -->
<h2><?php _e( 'Activity', 'cf-civicrm' ); ?></h2>
<div id="{{_id}}_activity_type_id" class="caldera-config-group">
	<label><?php _e( 'Activity Type', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field activity_type_id">
		<select class="block-input field-config" name="{{_name}}[activity_type_id]">
		<?php foreach ( $activities['values'] as $key => $value ) { ?>
			<option value="<?php echo esc_attr( $value['key'] ); ?>" {{#is activity_type_id value=<?php echo $value['key']; ?>}}selected="selected"{{/is}}><?php echo esc_html( $value['value'] ); ?></option>
		<?php } ?>
		</select>
	</div>
    <div class="is_mapped_field caldera-config-field">
        <label><input type="checkbox" name="{{_name}}[is_mapped_field]" value="1" {{#if is_mapped_field}}checked="checked"{{/if}}><?php _e( 'Use Activity Type mapped field.', 'cf-civicrm' ); ?></label>
    </div>
    <div class="mapped_activity_type_id caldera-config-field">
        <input type="text" class="block-input field-config magic-tag-enabled caldera-field-bind" name="{{_name}}[mapped_activity_type_id]" value="{{mapped_activity_type_id}}">
    </div>
</div>

<!-- Activity status -->
<div id="{{_id}}_activity_status_id" class="caldera-config-group">
	<label><?php _e( 'Activity Status', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field">
		<select class="block-input field-config" name="{{_name}}[status_id]">
		<?php foreach ( $activity_status['values'] as $key => $value ) { ?>
			<option value="<?php echo esc_attr( $value['key'] ); ?>" {{#is status_id value=<?php echo $value['key']; ?>}}selected="selected"{{/is}}><?php echo esc_html( $value['value'] ); ?></option>
		<?php } ?>
		</select>
	</div>
</div>

<!-- Campaign -->
<div id="{{_id}}_campaign_id" class="caldera-config-group">
	<label><?php _e( 'Campaign', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field">
		<select class="block-input field-config" name="{{_name}}[campaign_id]">
		<option value="" {{#is campaign_id value=""}}selected="selected"{{/is}}></option>
		<?php foreach ( $campaign_id['values'] as $key => $value ) { ?>
			<option value="<?php echo esc_attr( $value['id'] ); ?>" {{#is campaign_id value=<?php echo $value['id']; ?>}}selected="selected"{{/is}}><?php echo esc_html( $value['title'] ); ?></option>
		<?php } ?>
		</select>
	</div>
</div>

<hr style="clear: both;" />

<h2><?php _e( 'Activity fields', 'cf-civicrm' ); ?></h2>
<?php
	foreach ( $activityFields as $key => $value ) {
		if( ! in_array( $key, $ignore_fields ) ) { ?>
	<div id="{{_id}}_<?php echo esc_attr( $key ); ?>" class="caldera-config-group">
		<label><?php echo esc_html( $value ); ?> </label>
		<div class="caldera-config-field">
			<?php
				echo '{{{_field ';
				if ( $key == 'file_id' ) echo 'type="advanced_file,file,cf2_file" ';
				echo 'slug="' . $key . '"}}}';
			?>
		</div>
	</div>
<?php } } ?>

<div id="{{_id}}_target_contact_id_group" class="caldera-config-group">
	<label><?php _e( 'Target Contact ID', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field target_contact_id">
		<select id="{{_id}}_target_contact_id" class="block-input field-config" style="width: 100%;" nonce="<?php echo wp_create_nonce('admin_get_civi_contact'); ?>" name="{{_name}}[target_contact_id]">
		</select>
	</div>
    <div class="is_target_mapped_field caldera-config-field">
        <label><input type="checkbox" name="{{_name}}[is_target_mapped_field]" value="1" {{#if is_target_mapped_field}}checked="checked"{{/if}}><?php _e( 'Use Target Contact mapped field.', 'cf-civicrm' ); ?></label>
    </div>
    <div class="mapped_target_contact_id caldera-config-field">
        <input type="text" class="block-input field-config magic-tag-enabled caldera-field-bind" name="{{_name}}[mapped_target_contact_id]" value="{{mapped_target_contact_id}}">
    </div>
</div>

<div id="{{_id}}_source_contact_id_group" class="caldera-config-group">
	<label><?php _e( 'Source Contact ID', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field source_contact_id">
		<select id="{{_id}}_source_contact_id" class="block-input field-config" style="width: 100%;" nonce="<?php echo wp_create_nonce('admin_get_civi_contact'); ?>" name="{{_name}}[source_contact_id]">
		</select>
	</div>
    <div class="is_source_mapped_field caldera-config-field">
        <label><input type="checkbox" name="{{_name}}[is_source_mapped_field]" value="1" {{#if is_source_mapped_field}}checked="checked"{{/if}}><?php _e( 'Use Source Contact mapped field.', 'cf-civicrm' ); ?></label>
    </div>
    <div class="mapped_source_contact_id caldera-config-field">
        <input type="text" class="block-input field-config magic-tag-enabled caldera-field-bind" name="{{_name}}[mapped_source_contact_id]" value="{{mapped_source_contact_id}}">
    </div>
</div>

<div id="{{_id}}_assignee_contact_id_group" class="caldera-config-group">
	<label><?php _e( 'Assignee Contact ID', 'cf-civicrm' ); ?></label>
	<div class="caldera-config-field assignee_contact_id">
		<select id="{{_id}}_assignee_contact_id" class="block-input field-config" style="width: 100%;" nonce="<?php echo wp_create_nonce('admin_get_civi_contact'); ?>" name="{{_name}}[assignee_contact_id]">
		</select>
	</div>
    <div class="is_assignee_mapped_field caldera-config-field">
        <label><input type="checkbox" name="{{_name}}[is_assignee_mapped_field]" value="1" {{#if is_assignee_mapped_field}}checked="checked"{{/if}}><?php _e( 'Use Assignee Contact mapped field.', 'cf-civicrm' ); ?></label>
    </div>
    <div class="mapped_assignee_contact_id caldera-config-field">
        <input type="text" class="block-input field-config magic-tag-enabled caldera-field-bind" name="{{_name}}[mapped_assignee_contact_id]" value="{{mapped_assignee_contact_id}}">
    </div>
</div>

<script>
	jQuery(document).ready( function($) {
		var pid_prefix = '#{{_id}}_',
		select2_fields = [ {
				field: 'target_contact_id',
				value: '{{target_contact_id}}'
			},
			{
				field: 'source_contact_id',
				value: '{{source_contact_id}}'
			},
			{
				field: 'assignee_contact_id',
				value: '{{assignee_contact_id}}'
			}
		]
		.map( function( obj ){
			return {
				selector: pid_prefix + obj.field,
				value: obj.value
			}
		} )
		.map( function( field ){
			cfc_select2_defaults( field.selector, field.value );
		} );

		// mapping fields
        var prId = '{{_id}}',
            activity_type_id = '#' + prId + '_activity_type_id',
            target_contact_id = '#' + prId + '_target_contact_id_group',
            source_contact_id = '#' + prId + '_source_contact_id_group',
            assignee_contact_id = '#' + prId + '_assignee_contact_id_group';
        $( activity_type_id + ' .is_mapped_field input' ).on( 'change', function( i, el ) {
            var is_mapped_field = $( this ).prop( 'checked' );
            $( '.mapped_activity_type_id', $( activity_type_id ) ).toggle( is_mapped_field );
            $( '.activity_type_id', $( activity_type_id ) ).toggle( ! is_mapped_field );
        } ).trigger( 'change' );
        $( target_contact_id + ' .is_target_mapped_field input' ).on( 'change', function( i, el ) {
            var is_mapped_field = $( this ).prop( 'checked' );
            $( '.mapped_target_contact_id', $( target_contact_id ) ).toggle( is_mapped_field );
            $( '.target_contact_id', $( target_contact_id ) ).toggle( ! is_mapped_field );
        } ).trigger( 'change' );
      $( source_contact_id + ' .is_source_mapped_field input' ).on( 'change', function( i, el ) {
            var is_mapped_field = $( this ).prop( 'checked' );
            $( '.mapped_source_contact_id', $( source_contact_id ) ).toggle( is_mapped_field );
            $( '.source_contact_id', $( source_contact_id ) ).toggle( ! is_mapped_field );
      } ).trigger( 'change' );
        $( assignee_contact_id + ' .is_assignee_mapped_field input' ).on( 'change', function( i, el ) {
            var is_mapped_field = $( this ).prop( 'checked' );
            $( '.mapped_assignee_contact_id', $( assignee_contact_id ) ).toggle( is_mapped_field );
            $( '.assignee_contact_id', $( assignee_contact_id ) ).toggle( ! is_mapped_field );
        } ).trigger( 'change' );
	} );
</script>
