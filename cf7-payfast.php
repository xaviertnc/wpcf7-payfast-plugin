<?php
/**
 * Plugin Name: Contact Form 7 - PayFAST integration
 * Description: An add-on for Contact Form 7 that forwards form submissions to PayFAST.
 * Version: 1.0.0
 * Author: C. Moller
 * Author URI: http://www.webchamp.co.za
 * License: GPLv3
 */


/**
 * Verify CF7 dependencies.
 */
function cf7_payfast_admin_notice()
{
  // Verify that CF7 is active and updated to the required version (currently 3.9.0)
  if ( is_plugin_active('contact-form-7/wp-contact-form-7.php') )
  {
    $wpcf7_path = plugin_dir_path( dirname(__FILE__) ) . 'contact-form-7/wp-contact-form-7.php';
    $wpcf7_plugin_data = get_plugin_data( $wpcf7_path, false, false);
    $wpcf7_version = (int)preg_replace('/[.]/', '', $wpcf7_plugin_data['Version']);
    // CF7 drops the ending ".0" for new major releases
    // (e.g. Version 4.0 instead of 4.0.0...which would make the above version "40")
    // We need to make sure this value has a digit in the 100s place.
    if ( $wpcf7_version < 100 )
    {
      $wpcf7_version = $wpcf7_version * 10;
    }
    // If CF7 version is < 3.9.0
    if ( $wpcf7_version < 390 )
    {
      echo '<div class="error"><p><strong>Warning: </strong>',
        'Contact Form 7 - SOAP Forward requires that you have the latest version of ',
        'Contact Form 7 installed. Please upgrade now.</p></div>';
    }
  }
  // If it's not installed and activated, throw an error
  else {
    echo '<div class="error"><p>Contact Form 7 is not activated. ',
      'The Contact Form 7 Plugin must be installed and activated before ',
      'you can use the PayFAST integration.</p></div>';
  }
}

add_action( 'admin_notices', 'cf7_payfast_admin_notice' );



/**
 * Disable Contact Form 7 JavaScript completely
 */
add_filter( 'wpcf7_load_js', '__return_false' );



/**
 * Adds a box to the main column on the form edit page.
 *
 * CF7 < 4.2
 */
function cf7_payfast_add_meta_boxes()
{
  add_meta_box( 'cf7-payfast-settings', 'PayFAST', 'cf7_payfast_metaboxes', '', 'form', 'low');
}

add_action( 'wpcf7_add_meta_boxes', 'cf7_payfast_add_meta_boxes' );



/**
 * Adds a tab to the editor on the form edit page.
 *
 * CF7 >= 4.2
 */
function cf7_payfast_add_page_panels($panels)
{
  $panels['payfast-panel'] = array( 'title' => 'PayFAST', 'callback' => 'cf7_payfast_panel_meta' );
  return $panels;
}

add_action( 'wpcf7_editor_panels', 'cf7_payfast_add_page_panels' );



// Create the meta boxes (CF7 < 4.2)
function cf7_payfast_metaboxes( $post )
{
    wp_nonce_field( 'cf7_payfast_metaboxes', 'cf7_payfast_metaboxes_nonce' );
    $cf7_payfast = get_post_meta( $post->id(), '_cf7_payfast_key', true );

    // The meta box content
    $dropdown_options = array (
      'echo' => 0,
      'name' => 'cf7-payfast-success-page-id',
      'show_option_none' => '--',
      'option_none_value' => '0',
      'selected' => $cf7_payfast
    );

    echo '<fieldset>
            <legend>Select a page to redirect to after a successful payment.</legend>' .
            wp_dropdown_pages( $dropdown_options ) .
         '</fieldset>';
}



// Create the panel inputs (CF7 >= 4.2)
function cf7_payfast_panel_meta( $post )
{
  wp_nonce_field( 'cf7_payfast_metaboxes', 'cf7_payfast_metaboxes_nonce' );
  $cf7_payfast = get_post_meta( $post->id(), '_cf7_payfast_key', true );

  // The meta box content
  $dropdown_options = array (
    'echo' => 0,
    'name' => 'cf7-payfast-success-page-id',
    'show_option_none' => '--',
    'option_none_value' => '0',
    'selected' => $cf7_payfast
  );

  echo '<h3>Pay Success Settings</h3>
        <fieldset>
          <legend>Select a page to redirect to after a successful payment.</legend>' .
          wp_dropdown_pages( $dropdown_options ) .
       '</fieldset>';
}



// Store Success Page Info
function cf7_payfast_save_contact_form( $contact_form )
{
  $contact_form_id = $contact_form->id();

  if ( !isset( $_POST ) || empty( $_POST ) || !isset( $_POST['cf7-payfast-success-page-id'] ) )
  {
    return;
  }
  else
  {
    // Verify that the nonce is valid.
    if ( ! wp_verify_nonce( $_POST['cf7_payfast_metaboxes_nonce'], 'cf7_payfast_metaboxes' ) )
    {
      return;
    }
    // Update the stored value
    // update_post_meta( $contact_form_id, '_cf7_payfast_key', $_POST['cf7-redirect-page-id'] );
  }
}

add_action( 'wpcf7_after_save', 'cf7_payfast_save_contact_form' );



/**
 * Copy Redirect page key and assign it to duplicate form
 */
function cf7_payfast_after_form_create( $contact_form )
{
    $contact_form_id = $contact_form->id();

    // Get the old form ID
    if ( !empty( $_REQUEST['post'] ) && !empty( $_REQUEST['_wpnonce'] ) ) {
        $old_form_id = get_post_meta( $_REQUEST['post'], '_cf7_payfast_key', true );
    }
    // Update the duplicated form
    update_post_meta( $contact_form_id, '_cf7_payfast_key', $old_form_id );
}

add_action( 'wpcf7_after_create', 'cf7_payfast_after_form_create' );



/**
 * Redirect the user, after a successful email is sent
 */
function cf7_payfast_form_submitted( $contact_form )
{
  $submission = WPCF7_Submission::get_instance() ?: array();

  echo '<pre>Status: ', print_r($submission->get_status(), true), '</pre>';
  echo '<pre>Post Data: ', print_r($submission->get_posted_data(), true), '</pre>';

  // try
  // {
  //   $response = $soapClient->SaveLead($soapParams);
  // }
  // catch (\Exception $ex)
  // {
  //   $response = $ex->getMessage();
  // }

  echo '<pre>', print_r($response, true), '</pre>';

  die();
}

add_action( 'wpcf7_mail_sent', 'cf7_payfast_form_submitted' );
