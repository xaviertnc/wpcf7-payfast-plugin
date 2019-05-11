<?php
/**
 * Plugin Name: Contact Form 7 - PayFAST integration
 * Description: An add-on for Contact Form 7 that forwards form submissions to PayFAST.
 * Version: 1.0.0
 * Author: C. Moller
 * Author URI: http://www.webchamp.co.za
 * Text Domain: nm
 * License: GPLv3
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/xaviertnc/wpcf7-payfast-plugin
 */


// ---------------
// Support Classes
// ---------------
include 'Logger.php';
include 'PayFast.php';



// ----------------
// Plugin constants
// ----------------

if ( ! defined(__PAYFAST_MODE__))
{
  define('__PAYFAST_MODE__', 'SANDBOX'); // or LIVE or SANDBOX
}

if ( ! defined('CF7_PAYFAST_URL'))
{
  define('CF7_PAYFAST_URL', plugin_dir_url( __FILE__ ));
}

if ( ! defined('CF7_PAYFAST_PATH'))
{
  define('CF7_PAYFAST_PATH', plugin_dir_path( __FILE__ ));
}



// ----------
// Main class
// ----------

/**
 * Class CF7_Payfast_Plugin
 *
 * This class
 *  - checks if the CF7 plugin is present and warns if not
 *  - creates a PAYFAST options tab under CF7 admin
 *  - adds API hooks for ITN responses
 *  - sends confirmation emails
 *
 */
class CF7_Payfast_Plugin
{

  /**
   * CF7_Payfast constructor.
   *
   * The main plugin actions registered for WordPress
   */
  public function __construct()
  {
    // Warns if we don't have the Contact Form 7 plugin installed
    // or if the plugin version is too OLD!
    add_action('admin_notices', array($this, 'adminNotice'));

    // Handle external API calls
    add_action('admin_post_nopriv', array($this, 'apiHandlePayfastItnRequest'));
    add_action('admin_post', array($this, 'apiHandlePayfastItnRequest'));

    // Add a settings META BOX or TAB PANEL inside the Contact Form 7 admin area.
    add_action('add_meta_boxes', array($this, 'addAdminMetaBoxes'));
    add_action('wpcf7_editor_panels', array($this, 'addAdminTabPanels'));

    add_action('wpcf7_after_create', array($this, 'afterCreateContactFrom'));
    add_action('wpcf7_after_save', array($this, 'afterSaveContactForm'));
    add_action('wpcf7_mail_sent', array($this, 'afterContactFormMailSent'));

    // Disable Contact Form 7 JavaScript completely
    add_filter('wpcf7_load_js', '__return_false');
  }


  /**
   * Safely gets a value from an array, given a potentially non-existing KEY value.
   * @param  array  $array   [description]
   * @param  string $key     [description]
   * @param  mixed  $default [description]
   * @return mixed           [description]
   */
  private function arrayGet(array $array, $key, $default = null)
  {
    return isset($array[$key]) ? $array[$key] : $default;
  }


  /**
   * Verify CF7 dependencies.
   */
  public function adminNotice()
  {
    // Verify that CF7 is active and updated to the required version (currently 3.9.0)
    if (is_plugin_active('contact-form-7/wp-contact-form-7.php'))
    {
      $wpcf7_path = plugin_dir_path( dirname(__FILE__) ) . 'contact-form-7/wp-contact-form-7.php';
      $wpcf7_plugin_data = get_plugin_data($wpcf7_path, false, false);
      $wpcf7_version = (int)preg_replace('/[.]/', '', $wpcf7_plugin_data['Version']);

      // CF7 drops the ending ".0" for new major releases
      // (e.g. Version 4.0 instead of 4.0.0...which would make the above version "40")
      // We need to make sure this value has a digit in the 100s place.
      if ($wpcf7_version < 100)
      {
        $wpcf7_version = $wpcf7_version * 10;
      }

      // If CF7 version is < 3.9.0
      if ($wpcf7_version < 390)
      {
        echo '<div class="error"><p><strong>Warning: </strong>',
          'Contact Form 7 - SOAP Forward requires that you have the latest version of ',
          'Contact Form 7 installed. Please upgrade now.</p></div>';
      }
    }
    // If it's not installed and activated, throw an error
    else
    {
      echo '<div class="error"><p>Contact Form 7 is not activated. ',
        'The Contact Form 7 Plugin must be installed and activated before ',
        'you can use the PayFAST integration.</p></div>';
    }
  }


  /**
   * CF7 < 4.2
   * Adds a box to the main column on the form edit page.
   */
  public function addAdminMetaBoxes()
  {
    add_meta_box('cf7-payfast-settings', 'PayFAST', array($this, 'renderMetaBoxes'), '', 'form', 'low');
  }


  /**
   * CF7 >= 4.2
   * Adds a tab to the editor on the form edit page.
   * @param array $panels [description]
   */
  public function addAdminTabPanels($panels)
  {
    $panels['payfast-panel'] = array('title' => 'PayFAST', 'callback' => array($this, 'renderTabPanels'));
    return $panels;
  }


  /**
   * Renders the HTML for our PayFAST integration settings. (Old school style - CF7 < 4.2)
   * @param  WP_Post $post A Wordpress POST object representing the current CF7 form.
   * @return string Meta boxes HTML
   */
  public function renderMetaBoxes($post)
  {
    // NOTE: Contact forms are saved as Wordpress posts, so "$post" actually
    // represents the saved CF7 form and $post->id === FORM ID
    wp_nonce_field('cf7_payfast_metaboxes', 'cf7_payfast_metaboxes_nonce');
    $cf7_payfast = get_post_meta($post->id(), '_cf7_payfast_key', true);

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
            wp_dropdown_pages($dropdown_options) .
         '</fieldset>';
  }


  /**
   * Renders the HTML for our PayFAST integration admin settings panel. (CF7 >= 4.2)
   * @param  WP_Post $post A Wordpress POST object representing the current CF7 form.
   * @return string Meta boxes HTML
   */
  public function renderTabPanels( $post )
  {
    wp_nonce_field('cf7_payfast_metaboxes', 'cf7_payfast_metaboxes_nonce');
    $cf7_payfast = get_post_meta($post->id(), '_cf7_payfast_key', true);

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
            wp_dropdown_pages($dropdown_options) .
         '</fieldset>';
  }


  /**
   * Copy Redirect page key and assign it to duplicate form
   * @param  [type] $contact_form [description]
   * @return [type]               [description]
   */
  public function afterCreateContactFrom($contact_form)
  {
    $contact_form_id = $contact_form->id();

    // Get the old form ID
    if ( ! empty($_REQUEST['post']) && !empty( $_REQUEST['_wpnonce']))
    {
      $old_form_id = get_post_meta($_REQUEST['post'], '_cf7_payfast_key', true);
    }

    // Update the duplicated form
    update_post_meta($contact_form_id, '_cf7_payfast_key', $old_form_id);
  }


  /**
   * Save CF7 PayFast integration settings.
   * @param  CF7_Form $contact_form CF7 form object?
   * @return NULL
   */
  public function afterSaveContactForm($contact_form)
  {
    $contact_form_id = $contact_form->id();

    if (empty($_POST)) { return; }

    if (isset($_POST['cf7-payfast-success-page-id']))
    {
      // Verify that the nonce is valid.
      if (wp_verify_nonce($_POST['cf7_payfast_metaboxes_nonce'], 'cf7_payfast_metaboxes'))
      {
        // Update the stored value
        // update_post_meta( $contact_form_id, '_cf7_payfast_key', $_POST['cf7-redirect-page-id'] );
      }
    }
  }


  /**
   * Redirect the user, after a successful email is sent
   * @param  [type] $contact_form [description]
   * @return [type]               [description]
   */
  public function afterContactFormMailSent($contact_form)
  {
    $app = new stdClass();
    $payFastConfig = new stdClass();

    $payFastConfig->mode = __PAYFAST_MODE__;

    if ($payFastConfig->mode == 'LIVE') {
      $payFastConfig->merchantId  = '11266578';
      $payFastConfig->merchantKey = 'tiz0cq88aqc0a';
      $payFastConfig->passphrase  = 'Doen jou eie ding';
    }

    else { // SANDBOX
      $payFastConfig->merchantId  = '10005824';
      $payFastConfig->merchantKey = 'l9p20jqbzs762';
      $payFastConfig->passphrase  = '';
    }

    $log = new OneFile\Logger(__DIR__);
    $log->setFilename('debug_' . $log->getDate() .  '.log');
    $submission = WPCF7_Submission::get_instance() ?: array();
    $postedData = $submission->get_posted_data();
    $log->debug('CF7-STATUS: ' . $submission->get_status());
    $log->debug('CF7-POSTED-DATA: ' . print_r($postedData, true));

    $isCard = true;
    $payment_id = 100;
    $order_id = 101;

    try
    {
      // SETUP PAYFAST SERVICE
      $payfast = new OneFile\PayFast($payFastConfig->mode, ['logger' => $log, 'debug' => true]);
      $itnData = [
        'merchant_id'   => $payFastConfig->merchantId,
        'merchant_key'  => $payFastConfig->merchantKey,
        'return_url'    => get_site_url(null, 'cf7-payfast-success'),
        'cancel_url'    => get_site_url(null, 'cf7-payfast-cancel'),
        'notify_url'    => esc_url(admin_url('admin-post.php')),
        'name_first'    => $payfast->sandboxMode ? 'Test' : cf7_array_get($postedData, 'your-name', 'noname'),
        'name_last'     => $payfast->sandboxMode ? 'User' : cf7_array_get($postedData, 'your-lastname'),
        'email_address' => $payfast->sandboxMode ? 'sbtu01@payfast.co.za' : cf7_array_get($postedData, 'your-email'),
        'cell_number'   => cf7_array_get($postedData, 'your-phone', '0820000000'),
        'm_payment_id'  => $payment_id,
        'amount'        => cf7_array_get($postedData, 'donation-amount', 99),
        'item_name'     => 'GHEX AFRICA - Donation towards Conference costs',
        'custom_int1'   => $order_id,
        'custom_str1'   => 'GHEX DONATION',
        'payment_method'=> $isCard ? 'cc' : 'eft'  // eft,cc,dd,bc,mp,mc,cd
      ];

      $itnDataAsString = $payfast->stringifyItnData($itnData, 'removeEmptyItems');
      $itnSignature = $payfast->generateItnSignature($itnDataAsString, $payFastConfig->passphrase);

      // REDIRECT TO PAYFAST.COM
      $goto = 'https://' . $payfast->hostname . $payfast->itnProcessUri .
        '?' . $itnDataAsString . '&signature=' . $itnSignature;

      $log->cf7_submit_ok('goto = ' . $goto);

      if (wp_redirect($goto))
      {
        exit;
      }

    }
    catch (\Exception $ex)
    {
      echo $ex->getMessage();
      echo '<pre>Status: ', print_r($submission->get_status(), true), '</pre>';
      echo '<pre>Post Data: ', print_r($submission->get_posted_data(), true), '</pre>';
      exit;
    }
  }


  /**
   * Handle PayFAST ITN "ping back" requests
   */
  public function apiHandlePayfastItnRequest()
  {
    $log = new OneFile\Logger(__DIR__);
    $log->setFilename('itn_' . $log->getDate() .  '.log');
    $log->itn_post('ITN POST: ' . print_r($_REQUEST, true));
    $payfast = new OneFile\PayFast(__PAYFAST_MODE__, ['logger' => $log, 'debug' => true]);
    $payfast->aknowledgeItnRequest();
    exit;
  }

} // end Class: CF7_Payfast_Plugin


new CF7_Payfast_Plugin;
