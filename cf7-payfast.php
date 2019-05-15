<?php
/**
 * Plugin Name: Contact Form 7 - PayFAST integration
 * Description: A Contact Form 7 extension that redirects to PayFAST on submit.
 * Version: 1.1.0
 * Author: C. Moller
 * Author URI: http://www.webchamp.co.za
 * License: GPLv3
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/xaviertnc/wpcf7-payfast-plugin
 */


// ---------------
// Support Classes
// ---------------
include_once 'Logger.php';
include_once 'PayFast.php';


// ----------
// Main class
// ----------

/**
 * Class CF7_Payfast_Plugin
 *
 * This class
 *  - checks if the CF7 plugin is present and warns if not
 *  - creates a tab-panel or metaboxes for PAYFAST settings
 *    inside the CF7 admin settings page
 *  - adds API hooks for ITN responses
 *  - sends confirmation emails
 *
 */
class CF7_Payfast_Plugin
{

  private $submissions = array();


  /**
   * CF7_Payfast_Plugin constructor.
   *
   * The main plugin actions registered for WordPress
   */
  public function __construct()
  {
    if ( ! session_id())
    {
      session_start();
    }

    //  plugin functions
    register_activation_hook(__FILE__, array($this, 'activatePlugin'));
    register_deactivation_hook( __FILE__, array($this, 'deactivatePlugin'));
    register_uninstall_hook(__FILE__, array('CF7_Payfast_Plugin', 'deactivatePlugin'));

    add_action('wpcf7_init', array($this, 'addCustomTags'));

    // Generate Admin From Tag
    add_action('wpcf7_admin_init', array($this, 'addTagGeneratorPopup'), 50);

    // Add a settings META BOX or TAB PANEL inside the CF7 admin area.
    add_action('add_meta_boxes', array($this, 'addSettingsMetaBoxes'));
    add_action('wpcf7_editor_panels', array($this, 'addSettingsTabPanel'));

    add_action('wpcf7_after_save', array($this, 'afterContactFormSave'));
    add_action('wpcf7_after_create', array($this, 'afterContactFromCreate'));
    add_action('wpcf7_before_send_mail', array($this, 'beforeSendMail'));

    // Handle external API calls
    add_action('admin_post', array($this, 'apiHandlePayfastItnRequest'));
    add_action('admin_post_nopriv', array($this, 'apiHandlePayfastItnRequest'));

    // Warns if we don't have the Contact Form 7 plugin installed
    // or if the plugin version is too OLD!
    add_action('admin_notices', array($this, 'renderAdminNotices'));

    // Disable Contact Form 7 JavaScript completely
    add_filter('wpcf7_load_js', '__return_false');
  }


  /**
   * Safely gets a value from an array, given a potentially non-existing KEY value.
   * @param  array  $array
   * @param  string $key
   * @param  mixed  $default Alterante value if the array value is FALSY
   * @return mixed           Array value with key OR default
   */
  private function arrayGet(array $array, $key, $default = null)
  {
    return empty($array[$key]) ? $default : $array[$key];
  }


  public function uniqid($length = 13)
  {
    // uniqid gives 13 chars, but you could adjust it to your needs.
    if (function_exists("random_bytes")) {
      $bytes = random_bytes(ceil($length / 2));
      return substr(bin2hex($bytes), 0, $length);
    }
    if (function_exists("openssl_random_pseudo_bytes")) {
      $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
      return substr(bin2hex($bytes), 0, $length);
    }
    return uniqid();
  }


  /**
   * Start session if not already started
   */
  public function changeSession($newSessionID, $log)
  {
    $oldSessionID = session_id();
    $log->changeSession('Current Session ID = ' . $oldSessionID);
    $log->changeSession('Current $_SESSION = ' . (isset($_SESSION) ? print_r($_SESSION, true) : 'none'));
    $log->changeSession('New Session ID = ' . $newSessionID);
    if ( ! $oldSessionID)
    {
      session_id($newSessionID);
      session_start();
    }
    elseif ($newSessionID != $oldSessionID)
    {
      $log->changeSession('WRITE and CLOSE the current session.');
      session_write_close();
      session_id($newSessionID);
      session_start();
    }
    $log->changeSession('New $_SESSION = ' . print_r($_SESSION, true));
    return $oldSessionID;
  }


  public function stashPaymentToSession($stashKey, array $paymentData, $log)
  {
    $log->stashPaymentToSession('STASH PAYMENT DATA to session. key = ' . $stashKey);
    if ( ! $stashKey) { return; }
    $oldSessionID = $this->changeSession($stashKey, $log);
    $_SESSION = array_merge($_SESSION, $paymentData);
    if ($oldSessionID and $oldSessionID != $stashKey)
    {
      $this->changeSession($oldSessionID, $log);
    }
  }


  public function getPaymentFromSession($stashKey = null, $log)
  {
    $log->getPaymentFromSession('GET STASHED PAYMENT DATA from session. key = ' . $stashKey);
    if ( ! $stashKey) { return; }
    $oldSessionID = $this->changeSession($stashKey, $log);
    $paymentData = array_merge(array(), $_SESSION);
    if ($oldSessionID and $oldSessionID != $stashKey)
    {
      $log->getPaymentFromSession('DESTROY the submission data session. i.e. FLASH session!');
      $_SESSION = [];
      session_destroy();
      $log->getPaymentFromSession('Change back to the session before last.');
      $this->changeSession($oldSessionID, $log);
    }
    return $paymentData;
  }


  public function wpdbInsertPayment(array $payment, $log)
  {
    global $wpdb;
    $tableName = $wpdb->prefix . 'payments_cf7';
    $log->wpdbInsertPayment('PAYMENT = ' . print_r($payment, true));
    $data = array(
      'status'       => 'PENDING',
      'method'       => $this->arrayGet($payment, 'method'),
      'amount'       => $this->arrayGet($payment, 'amount'),
      'cf7_form_ref' => $this->arrayGet($payment, '_wpcf7_unit_tag'),
      'remote_id'    => $this->arrayGet($payment, 'remote_ip'),
      'firstname'    => $this->arrayGet($payment, 'firstname'),
      'lastname'     => $this->arrayGet($payment, 'lastname'),
      'email'        => $this->arrayGet($payment, 'email'),
      'phone'        => $this->arrayGet($payment, 'phone'),
      'message'      => $this->arrayGet($payment, 'message'),
    );

    $data['fullname'] = $this->arrayGet($data, 'firstname');
    if ( ! empty($data['lastname']))
    {
      $data['fullname'] = trim($data['firstname'] . ' ' . data['lastname']);
    }

    $wpdb->insert($tablename, $data);

    return $wpdb->insert_id; // i.e. payment_id
  }


  public function wpdbUpdatePayment(array $payment, $log)
  {
    global $wpdb;
    $log->wpdbUpdatePayment('PAYMENT = ' . print_r($payment, true));
    $tableName = $wpdb->prefix . 'payments_cf7';
    $paymentID = $this->arrayGet($payment, 'id', 0);
    $data = array(
      'status'       => $this->arrayGet($payment, 'status'),
      'payfast_ref'  => $this->arrayGet($payment, 'pf_payment_id'),
      'payfast_fee'  => $this->arrayGet($payment, 'payfast_fee'),
      'amount_net'   => $this->arrayGet($payment, 'amount_net'),
      'confirmed_at' => current_time('mysql')
    );
    return $wpdb->update($tablename, $paymentID, $data);
  }


  public function wpdbDeletePayment(array $payment)
  {
    global $wpdb;
    $log->wpdbDeletePayment('PAYMENT = ' . print_r($payment, true));
    $tableName = $wpdb->prefix . 'payments_cf7';
    $paymentID = $this->arrayGet($payment, 'id', 0);
    return $wpdb->delete($tablename, $paymentID);
  }


  public function activatePlugin()
  {
    global $wpdb;

    $tableName = $wpdb->prefix . 'payments_cf7';

    $charsetCollate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $tableName (
      id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
      status varchar(16) NULL,
      method varchar(32) NOT NULL,
      amount decimal(10,2) NOT NULL,
      cf7_form_ref varchar(65) NULL,
      payfast_ref int(11) NOT NULL,
      payfast_fee decimal(10,2) NOT NULL,
      amount_net decimal(10,2) NOT NULL,
      remote_ip varchar(32) NULL,
      fullname varchar(64) NULL,
      firstname varchar(32) NULL,
      lastname varchar(32) NULL,
      email varchar(255) NULL,
      phone varchar(16) NULL,
      message text NULL,
      confirmed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
      PRIMARY KEY  (id)
    ) $charsetCollate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }


  public function deactivatePlugin() {}


  public static function uninstallPlugin()
  {
    // global $wpdb;
    // $tableName = $wpdb->prefix . 'payments_cf7';
    // $sql = "DROP TABLE IF EXISTS $tableName";
    // $wpdb->query($sql);
    // delete_option('cf7_payfast_global_options');
  }


  /**
   * Verify CF7 dependencies and render a WARNING notice if we find any issues.
   */
  public function renderAdminNotices()
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
          'The Contact Form 7 PayFAST plugin extension requires that you have ',
          'the latest version of Contact Form 7 installed. Please upgrade now.</p></div>';
      }
    }
    // If it's not installed and activated, throw an error
    else
    {
      echo '<div class="error"><p>Contact Form 7 is not activated. ',
        'The Contact Form 7 plugin must be installed and activated ',
        'before you can use the CF7 PayFAST plugin extension.</p></div>';
    }
  }


  /**
   * CF7 < 4.2
   * Adds a box to the main column on the form edit page.
   */
  public function addSettingsMetaBoxes()
  {
    add_meta_box('cf7-payfast-settings', 'PayFAST', array($this, 'renderSettingsMetaBoxes'), '', 'form', 'low');
  }


  /**
   * CF7 >= 4.2
   * Adds a tab-panel to the editor on the form edit page.
   * @param array $panels [description]
   */
  public function addSettingsTabPanel($panels)
  {
    $panels['payfast-panel'] = array('title' => 'PayFAST', 'callback' => array($this, 'renderSettingsTabPanel'));
    return $panels;
  }


  /**
   * Renders the HTML for our PayFAST integration settings. (Old school style - CF7 < 4.2)
   * @param  WP_Post $post A Wordpress POST object representing the current CF7 form.
   * @return string Meta boxes HTML
   */
  public function renderSettingsMetaBoxes($post)
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
  public function renderSettingsTabPanel( $post )
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


  public function addTagGeneratorPopup()
  {
    $tag_generator = WPCF7_TagGenerator::get_instance();
    $tag_generator->add('checkout', 'checkout', array($this, 'renderTagGeneratorPopup'));
  }


  // Display form in admin
  public function renderTagGeneratorPopup($contact_form, $args = '')
  {
    // Parse data and get our options
    $args = wp_parse_args( $args, array() );
    $type = 'payfast';
    ?>

    <div class="control-box">
      <fieldset>
        <legend>Generate a form-tag for a checkout submit button.</legend>
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row"><?php echo esc_html(__('Field type', 'contact-form-7')); ?></th>
              <td>
                <fieldset>
                <legend class="screen-reader-text"><?php echo esc_html(__( 'Field type', 'contact-form-7' )); ?></legend>
                <select name="tagtype">
                  <option value="paypal">PayPal</option>
                  <option value="payfast" selected>PayFAST</option>
                </select>
                </fieldset>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-values' ); ?>"><?php echo esc_html( __( 'Label', 'contact-form-7' ) ); ?></label></th>
              <td><input type="text" name="values" class="oneline" id="<?php echo esc_attr( $args['content'] . '-values' ); ?>" value="Donate Now!" /></td>
            </tr>
            <tr>
              <th scope="row"><label for="<?php echo esc_attr($args['content'] . '-id' ); ?>"><?php echo esc_html(__('Id attribute', 'contact-form-7')); ?></label></th>
              <td><input type="text" name="id" class="idvalue oneline option" id="<?php echo esc_attr($args['content'] . '-id'); ?>" /></td>
            </tr>
            <tr>
              <th scope="row"><label for="<?php echo esc_attr($args['content'] . '-class' ); ?>"><?php echo esc_html(__('Class attribute', 'contact-form-7')); ?></label></th>
              <td><input type="text" name="class" class="classvalue oneline option" id="<?php echo esc_attr($args['content'] . '-class'); ?>" /></td>
            </tr>
          </tbody>
        </table>
      </fieldset>
    </div>

    <div class="insert-box">
      <input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />
      <div class="submitbox">
        <input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr(__('Insert Tag', 'contact-form-7')); ?>" />
      </div>
      <br class="clear" />
    </div>

    <?php
  }


  /**
   * addCustomTags
   */
  public function addCustomTags()
  {
    // $log = new OneFile\Logger(__DIR__);
    // $log->setFilename('debug_' . $log->getDate() .  '.log');
    // $log->add_custom_tag('CUSTOM TAG: checkout');

    wpcf7_add_form_tag(
      array('payfast', 'paypal'),
      array($this, 'renderFormTag'),
      array('name-attr' => true)
    );

  }


  /**
   * renderFormTag
   * @param  WPCF7_FormTag $tag
   * @return string TAG HTML
   */
  public function renderFormTag($tag)
  {
    $tag = new WPCF7_FormTag($tag);

    // $log = new OneFile\Logger(__DIR__);
    // $log->setFilename('debug_' . $log->getDate() .  '.log');
    // $log->render_custom_tag('Tag: ' . print_r($tag, true));

    // if (empty($tag->name)) { return ''; }

    $class = wpcf7_form_controls_class($tag->type);

    $value = isset($tag->values[0]) ? $tag->values[0] : '';
    if (empty($value)) { $value = __('Send', 'contact-form-7'); }

    $atts = array();
    $atts['type'] = 'submit';
    $atts['name'] = 'checkout-submit';
    $atts['class'] = $tag->get_class_option($class);
    $atts['tabindex'] = $tag->get_option('tabindex', 'signed_int', true);
    $atts['value'] = $tag->type;

    $atts = wpcf7_format_atts($atts);

    return "<button $atts>" . esc_html($value) . '</button>';
  }


  /**
   * Copy the "old" contact form settings to the
   * new / duplicated form (if applicable)
   * @param  WPCF7_ContactForm $contactForm
   */
  public function afterContactFromCreate($contactForm)
  {
    $newFormPostID = $contactForm->id();
    $oldFormPostID = $this->arrayGet($_REQUEST, 'post');

    // Get the old form ID + transfer its settings to the duplicated form...
    if ($oldFormPostID && isset($_REQUEST['_wpnonce']))
    {
      $payFastSettings = get_post_meta($oldFormPostID, '_cf7_payfast_settings', true);
      update_post_meta($newFormPostID, '_cf7_payfast_settings', $payFastSettings);
    }
    else
    {
      // Default settings
      $payFastSettings = array(
        'mode'              => 'SANDBOX',
        'return_page_id'    => 0,
        'return_url'        => esc_url(get_site_url(null, 'payment-successful')),
        'cancel_page_id'    => 0,
        'cancel_url'        => esc_url(get_site_url(null, 'payment-canceled')),
        'merchant_id_live'  => '',
        'merchant_key_live' => '',
        'passphrase_live'   => '',
        'merchant_id_test'  => '10005824',
        'merchant_key_test' => 'l9p20jqbzs762',
        'passphrase_test'   => ''
      );

      if ( ! add_post_meta($newFormPostID, '_cf7_payfast_settings', $payFastSettings, true))
      {
        update_post_meta($newFormPostID, '_cf7_payfast_settings', $payFastSettings);
      }
    }
  }


  /**
   * Save PayFast integration settings after saving the contact form settings.
   * @param  WPCF7_ContactForm $contactForm CF7 form object?
   */
  public function afterContactFormSave($contactForm)
  {
    $cf7PostID = $contactForm->id();

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
   * Redirect the user, after a successful form submit.
   * @param  [type] $contactForm [description]
   * @return [type]               [description]
   */
  public function beforeSendMail($contactForm)
  {
    $log = new OneFile\Logger(__DIR__);
    $log->setFilename('debug_' . $log->getDate() .  '.log');

    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission) { return; }

    $payment = $submission->get_posted_data();

    $log->before_mail('CF7-STATUS: ' . $submission->get_status());
    $log->before_mail('CF7-PAYMENT-DATA: ' . print_r($payment, true));

    // NB: VERY IMPORTANT TEST!
    if ( ! isset($payment['checkout-submit'])) {
      $log->before_mail('Submission is NOT a PayFAST form submit... IGNORE!');
      return;
    }

    try
    {
      $itnData = [];

      $cf7PostID = $contactForm->id();

      $paymentDataStoreKey = 'cf7-pf-' . $this->uniqid();

      $payfastSettings = get_post_meta($cf7PostID, '_cf7_payfast_settings', true);
      if (empty($payfastSettings))
      {
        throw new Exception('CF7 beforeSendMail(), Payfast Settings cannot be empty!');
      }

      $passphrase = $payfastSettings['passphrase_test'];

      // [0], because `method` is a select-type field and hence
      // stored as an array of selected options in $_REQUEST / $_POST
      $payMethod = $this->arrayGet($payment, 'method', ['none'])[0];

      $payment['id'] = $this->wpdbInsertPayment($payment, $log);

      $remote_ip = $submission->get_meta('remote_ip');
      if ( ! $remote_ip) { $remote_ip = 'noip'; }
      $payment['remote_ip'] = $remote_ip;

      // SETUP PAYFAST SERVICE
      $payfast = new OneFile\PayFast(
        $this->arrayGet($payfastSettings, 'mode'),
        ['logger' => $log, 'debug' => true]
      );

      $itnData['merchantId']     = $this->arrayGet($payfastSettings, 'merchant_id_test');
      $itnData['merchantKey']    = $this->arrayGet($payfastSettings,'merchant_key_test');
      $itnData['return_url']     = $this->arrayGet($payfastSettings,'return_url');
      $itnData['cancel_url']     = $this->arrayGet($payfastSettings,'cancel_url');
      $itnData['notify_url']     = esc_url(admin_url('admin-post.php'));
      $itnData['name_first]']    = $this->arrayGet($payfastSettings, 'firstname_test', 'Test');
      $itnData['name_last']      = $this->arrayGet($payfastSettings, 'lastname_test', 'User');
      $itnData['email_address']  = $this->arrayGet($payfastSettings, 'email_test', 'sbtu01@payfast.co.za');
      $itnData['cell_number']    = $this->arrayGet($payfastSettings, 'phone_test', '0820000000');
      $itnData['m_payment_id']   = $this->arrayGet($payment, 'id', 0);
      $itnData['amount']         = $this->arrayGet($payment, 'amount', 9999);
      $itnData['item_name']      = $this->arrayGet($payment, 'product', 'No Product Description'); // 'GHEX AFRICA - Donation towards Conference costs'
      $itnData['custom_int1']    = $cf7PostID;
      $itnData['custom_str1']    = $paymentDataStoreKey;
      $itnData['payment_method'] = ($payMethod == 'EFT') ? 'eft' : 'cc';  // eft,cc,dd,bc,mp,mc,cd

      if ( ! $payfast->sandboxMode)
      {
        $passphrase = $payfastSettings['passphrase_live'];
        $itnData['name_first]']   = $this->arrayGet($payment, 'firstname', 'noname');
        $itnData['name_last']     = $this->arrayGet($payment, 'lastname' , 'noname');
        $itnData['email_address'] = $this->arrayGet($payment, 'email', 'nomail@payfast.co.za');
        $itnData['cell_number']   = $this->arrayGet($payment, 'phone', '0820000000');
      }

      $itnDataAsString = $payfast->stringifyItnData($itnData, 'removeEmptyItems');
      $itnSignature = $payfast->generateItnSignature($itnDataAsString, $passphrase);

      $payFastProcessUrl = 'https://' . $payfast->hostname . $payfast->itnProcessUri .
        '?' . $itnDataAsString . '&signature=' . $itnSignature;

      $log->before_mail('Payfast URL = ' . $payFastProcessUrl);

      // We'll need it later when we get the ITN post from PayPal.
      $this->stashPaymentToSession($paymentDataStoreKey, $payment, $log);

      // Prevent sending mails at this stage.
      // We first need to wait for the Payfast ITN reply to deremine if the
      // payment was successful.
      add_filter('wpcf7_skip_mail',  '__return_true');

      // Defer redirecting untill AFTER the submission process is completely done.
      // NOTE: We purposley set a LOW priority on this action callback so other
      // plugins with the same action can run first.
      add_action('wpcf7_mail_sent', function() use($payFastProcessUrl, $log) {
        $log->wpcf7_mail_sent('Redirecting to: ' . $payFastProcessUrl);
        if (wp_redirect($payFastProcessUrl)) { exit; }
      }, 100);
    }

    // WHOOPS!
    catch (\Exception $ex)
    {
      $log->error($ex->getMessage());
      return false;
    }
  }


  /**
   * Handle PayFAST ITN "ping back" requests
   */
  public function apiHandlePayfastItnRequest()
  {
    $log = new OneFile\Logger(__DIR__);
    $log->setFilename('itn_' . $log->getDate() .  '_log.php');
    $log->itn_post('apiHandlePayfastItnRequest(), $_REQUEST:' . print_r($_REQUEST, true));

    try {

      // TODO: Check that the CF7 plugin is present and active!

      $cf7PostID = $this->arrayGet($_REQUEST, 'custom_int1');
      $log->after_itn('cf7PostID: ' . $cf7PostID);

      if ( ! $cf7PostID)
      {
        // TODO: Send ERROR email?
        throw new Exception('PayFast ITN response: `custom_int1` cannot be empty!');
      }

      $payFastSettings = get_post_meta($cf7PostID, '_cf7_payfast_settings', true);

      if ( ! $payFastSettings)
      {
        // TODO: Send ERROR email?
        throw new Exception('CF7 - PayFast settings cannot be empty!');
      }

      // SETUP PAYFAST SERVICE
      $payfast = new OneFile\PayFast(
        $this->arrayGet($payFastSettings, 'mode'),
        ['logger' => $log, 'debug' => true]
      );

      $payfast->aknowledgeItnRequest();


      $paymentDataStoreKey = $this->arrayGet($_REQUEST, 'custom_str1');
      $log->after_itn('paymentDataStoreKey: ' . $paymentDataStoreKey);

      $payment = $this->getPaymentFromSession($paymentDataStoreKey, $log);

      // Security check...
      if ($this->arrayGet($payment, 'amount') != $this->arrayGet($_REQUEST, 'amount_gross', 0))
      {
        // TODO: Send ERROR email
        throw new Exception('apiHandlePayfastItnRequest(), ' .
          'CF7 POST and PayFAST ITN amounts do not match!');
      }

      $payment['id']          = $this->arrayGet($_REQUEST, 'm_payment_id');
      $payment['payfast_ref'] = $this->arrayGet($_REQUEST, 'pf_payment_id');
      $payment['payfast_fee'] = $this->arrayGet($_REQUEST, 'amount_fee');
      $payment['amount_net']  = $this->arrayGet($_REQUEST, 'amount_net');
      $payment['status']      = $this->arrayGet($_REQUEST, 'payment_status');

      // IMPORTANT: Clear `checkout-submit` to prevent recursively
      //            calling the `wpcf7_before_send_mail` action!
      unset($payment['checkout-submit']);

      // Check if PAYFAST PAYMENT was SUCCESS or FAIL!
      if ($this->arrayGet($_REQUEST, 'payment_status') == 'COMPLETE')
      {

        $contactForm = WPCF7_ContactForm::get_instance($cf7PostID);

        // Important $_REQUEST values:
        // ---------------------------
        // [m_payment_id] => 2
        // [pf_payment_id] => 812470
        // [payment_status] => COMPLETE
        // [item_name] => GHEX AFRICA - Donation towards ...
        // [item_description] =>
        // [amount_gross] => 699.00
        // [amount_fee] => -16.08
        // [amount_net] => 682.92

        // Necessary to make WPCF7_Submission::get_instance() work!
        $_POST = $payment;
        $log->after_itn('$_POST: ' . print_r($_POST, true));

        // NOTE: WPCF7_Submission::get_instance() behaves differently depending
        //       on wheter we provide a CONTACT_FORM arg or not.
        //       With a valid CONTACT FORM arg, it AUTO gets and validates
        //       any $_POST data and AUTO sends all configured emails!
        //       No need to call WPCF7_Submission::submit() or mail().
        $submission = WPCF7_Submission::get_instance($contactForm);
      }

      $this->wpdbUpdatePayment($payment, $log);

    }

    catch(Exception $e)
    {
      $log->itn_post($e->getMessage());
    }

  }


} // end Class: CF7_Payfast_Plugin


new CF7_Payfast_Plugin();
