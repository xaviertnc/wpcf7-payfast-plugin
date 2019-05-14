<?php
/**
 * Plugin Name: Contact Form 7 - PayFAST integration
 * Description: A Contact Form 7 extension that redirects to PayFAST on submit.
 * Version: 1.0.0
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



function wpcf7_payfast_uninstall()
{
  delete_option('cf7payfast_options');
}



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
    register_uninstall_hook(__FILE__, 'wpcf7_payfast_uninstall');

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


  public function storePostedData($submissionID, array $postedData, $log)
  {
    $log->storePostedData('STORE POSTED-DATA submissionID = ' . $submissionID);
    if ( ! $submissionID) { return; }
    $oldSessionID = $this->changeSession($submissionID, $log);
    $_SESSION = array_merge($_SESSION, $postedData);
    if ($oldSessionID and $oldSessionID != $submissionID)
    {
      $this->changeSession($oldSessionID, $log);
    }
  }


  public function getPostedData($submissionID = null, $log)
  {
    $log->getPostedData('GET POSTED-DATA submissionID = ' . $submissionID);
    if ( ! $submissionID) { return; }
    $oldSessionID = $this->changeSession($submissionID, $log);
    $postedData = array_merge(array(), $_SESSION);
    if ($oldSessionID and $oldSessionID != $submissionID)
    {
      $log->getPostedData('DESTROY the NEW SESSION and change back to the PREV SESSION!');
      session_destroy();
      $_SESSION = [];
      $this->changeSession($oldSessionID, $log);
    }
    return $postedData;
  }


  public function saveFormPost(array $postedData, $log)
  {
    $log->saveFormPost('SAVE FORM POST = ' . print_r($postedData, true));
    $cf7payfast_options = get_option('cf7payfast_options');
    $posts = json_decode($this->arrayGet($cf7payfast_options, 'form_posts', '[]'));
    $log->saveFormPost('options = ' . print_r($cf7payfast_options, true));
    $log->saveFormPost('posts = ' . print_r($posts, true));
    $id = $this->arrayGet($cf7payfast_options, 'next_pmt_id');
    $postedData['id'] = $id;
    $postedData['form'] = $this->arrayGet($postedData, '_wpcf7_unit_tag');
    $postedData['method'] = $this->arrayGet($postedData, 'method', ['None'])[0];
    $postedData['time'] = date('Y-m-d H:i:s');
    // Remove CF7 META Data
    unset($postedData['g-recaptcha-response']);
    unset($postedData['checkout-submit']);
    unset($postedData['_wpcf7_container_post']);
    unset($postedData['_wpcf7_unit_tag']);
    unset($postedData['_wpcf7_version']);
    unset($postedData['_wpcf7_locale']);
    unset($postedData['_wpcf7']);
    // Add POST
    $posts[] = $postedData;
    // JSON encode save POSTS
    $jsonFormPosts = json_encode($posts);
    $log->saveFormPost('jsonFormPosts = ' . print_r($jsonFormPosts, true));
    $cf7payfast_options['next_pmt_id'] = $id + 1;
    $cf7payfast_options['form_posts'] = $jsonFormPosts;
    update_option('cf7payfast_options', $cf7payfast_options);
    return $jsonFormPosts;
  }


  public function activatePlugin()
  {
    // default options
    $cf7payfast_options = array(
      'mode'              => 'SANDBOX',
      'return_url'        => esc_url(get_site_url(null, 'payment-successful')),
      'cancel_url'        => esc_url(get_site_url(null, 'payment-canceled')),
      'merchant_id_live'  => '',
      'merchant_key_live' => '',
      'passphrase_live'   => '',
      'merchant_id_test'  => '10005824',
      'merchant_key_test' => 'l9p20jqbzs762',
      'passphrase_test'   => '',
      'form_posts'        => '[]',
      'next_pmt_id'       => 1
    );

    add_option('cf7payfast_options', $cf7payfast_options);
  }


  public function deactivatePlugin()
  {
    unset($_SESSION['_cf7_payfast_submissions']);
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
   * @param  WPCF7_FormTag $tag [description]
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
   * Copy Redirect page key and assign it to duplicate form
   * @param  [type] $contact_form [description]
   * @return [type]               [description]
   */
  public function afterContactFromCreate($contact_form)
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
   * Save PayFast integration settings after saving the contact form settings.
   * @param  CF7_Form $contact_form CF7 form object?
   * @return NULL
   */
  public function afterContactFormSave($contact_form)
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
   * Redirect the user, after a successful form submit.
   * @param  [type] $contact_form [description]
   * @return [type]               [description]
   */
  public function beforeSendMail($contact_form)
  {
    $log = new OneFile\Logger(__DIR__);
    $log->setFilename('debug_' . $log->getDate() .  '.log');

    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission) { return; }

    $postedData = $submission->get_posted_data();
    $log->before_mail('CF7-STATUS: ' . $submission->get_status());
    $log->before_mail('CF7-POSTED-DATA: ' . print_r($postedData, true));

    // NB: VERY IMPORTANT TEST!
    if ( ! isset($postedData['checkout-submit'])) {
      $log->before_mail('Submission is NOT a PayFAST form submit... IGNORE!');
      return;
    }

    try
    {

      $app = new stdClass();
      $payFastConfig = new stdClass();

      $submissionID = 'cf7-pf-' . $this->uniqid();

      $cf7payfast_options = get_option('cf7payfast_options');

      if (empty($cf7payfast_options))
      {
        throw new Exception('`cf7payfast_options` cannot be empty... logic error? Check code!');
      }

      $payment_id = $this->arrayGet($cf7payfast_options, 'next_pmt_id');

      // SETUP PAYFAST SERVICE
      $payfast = new OneFile\PayFast(
        $this->arrayGet($cf7payfast_options, 'mode'),
        ['logger' => $log, 'debug' => true]
      );

      if ($payfast->sandboxMode)
      {
        $payFastConfig->merchantId  = $cf7payfast_options['merchant_id_test'];
        $payFastConfig->merchantKey = $cf7payfast_options['merchant_key_test'];
        $payFastConfig->passphrase  = $cf7payfast_options['passphrase_test'];
      }
      else
      {
        $payFastConfig->merchantId  = $cf7payfast_options['merchant_id_live'];
        $payFastConfig->merchantKey = $cf7payfast_options['merchant_key_live'];
        $payFastConfig->passphrase  = $cf7payfast_options['passphrase_live'];
      }

      $payMethod = $this->arrayGet($postedData, 'method', ['none'])[0];
      $payFastConfig->isCard = ($payMethod != 'EFT');

      $itnData = [
        'merchant_id'   => $payFastConfig->merchantId,
        'merchant_key'  => $payFastConfig->merchantKey,
        'return_url'    => $cf7payfast_options['return_url'],
        'cancel_url'    => $cf7payfast_options['cancel_url'],
        'notify_url'    => esc_url(admin_url('admin-post.php')),
        'name_first'    => $payfast->sandboxMode ? 'Test' : $this->arrayGet($postedData, 'your-name', 'noname'),
        'name_last'     => $payfast->sandboxMode ? 'User' : $this->arrayGet($postedData, 'your-lastname', 'none'),
        'email_address' => $payfast->sandboxMode ? 'sbtu01@payfast.co.za' : $this->arrayGet($postedData, 'your-email'),
        'cell_number'   => $this->arrayGet($postedData, 'your-phone', '0820000000'),
        'm_payment_id'  => $payment_id,
        'amount'        => $this->arrayGet($postedData, 'donation-amount', 999),
        'item_name'     => 'GHEX AFRICA - Donation towards Conference costs',
        'custom_int1'   => $contact_form->id(),
        'custom_str1'   => $submissionID,
        'payment_method'=> $payFastConfig->isCard ? 'cc' : 'eft'  // eft,cc,dd,bc,mp,mc,cd
      ];

      $itnDataAsString = $payfast->stringifyItnData($itnData, 'removeEmptyItems');
      $itnSignature = $payfast->generateItnSignature($itnDataAsString, $payFastConfig->passphrase);

      $payFastUrl = 'https://' . $payfast->hostname . $payfast->itnProcessUri .
        '?' . $itnDataAsString . '&signature=' . $itnSignature;

      $log->before_mail('Payfast URL = ' . $payFastUrl);

      $ip = $submission->get_meta('remote_ip');
      if ( ! $ip) { $ip = 'noip'; }
      $postedData['ip'] = $ip;

      $this->storePostedData($submissionID, $postedData, $log);

      // NOTE: Must be AFTER submission type check!
      add_filter('wpcf7_skip_mail',  '__return_true');

      // Defer redirecting untill AFTER the submission process is completely done.
      // NOTE: We purposley set a LOW priority on this action callback so other
      // plugins with the same action can run first.
      add_action('wpcf7_mail_sent', function() use($payFastUrl, $log) {
        $log->wpcf7_mail_sent('Redirecting to: ' . $payFastUrl);
        if (wp_redirect($payFastUrl)) { exit; }
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

      $cf7payfast_options = get_option('cf7payfast_options');

      if (empty($cf7payfast_options))
      {
        throw new Exception('`cf7payfast_options` cannot be empty... ' .
          'logic error? Check code!');
      }

      // SETUP PAYFAST SERVICE
      $payfast = new OneFile\PayFast(
        $this->arrayGet($cf7payfast_options, 'mode'),
        ['logger' => $log, 'debug' => true]
      );

      $payfast->aknowledgeItnRequest();

      $contact_form_id = $this->arrayGet($_REQUEST, 'custom_int1');
      $log->after_itn('contact_form_id: ' . $contact_form_id);

      $submissionID = $this->arrayGet($_REQUEST, 'custom_str1');
      $log->after_itn('submissionID: ' . $submissionID);

      if ( ! $contact_form_id) { return; }

      $_POST = $this->getPostedData($submissionID, $log);

      $_POST['pf_id'] = $_REQUEST['pf_payment_id'];
      $_POST['fee'] = $_REQUEST['amount_fee'];
      $_POST['net'] = $_REQUEST['amount_net'];

      // IMPORTANT: Clear `checkout-submit` to prevent recursively
      //            calling the `wpcf7_before_send_mail` action!
      unset($_POST['checkout-submit']);

      $log->after_itn('$_POST: ' . print_r($_POST, true));

      // Check if PAYFAST PAYMENT was SUCCESS or FAIL!
      if ($this->arrayGet($_REQUEST, 'payment_status') == 'COMPLETE')
      {
        $contact_form = WPCF7_ContactForm::get_instance($contact_form_id);

        // [m_payment_id] => 2
        // [pf_payment_id] => 812470
        // [payment_status] => COMPLETE
        // [item_name] => GHEX AFRICA - Donation towards Conference costs
        // [item_description] =>
        // [amount_gross] => 699.00
        // [amount_fee] => -16.08
        // [amount_net] => 682.92

        // NOTE: WPCF7_Submission::get_instance() behaves differently depending
        //       on wheter we provide a CONTACT_FORM arg or not.
        //       With a valid CONTACT FORM arg, it AUTO gets and validates
        //       any $_POST data and AUTO sends all configured emails!
        //       No need to call WPCF7_Submission::submit() or mail().
        $submission = WPCF7_Submission::get_instance($contact_form);

        $this->saveFormPost($_POST, $log);
      }
    }

    catch(Exception $e)
    {
      $log->itn_post($e->getMessage());
    }

  }


} // end Class: CF7_Payfast_Plugin


new CF7_Payfast_Plugin();
