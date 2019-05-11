<?php namespace OneFile;


use Exception;


/**
 * OneFile/PayFast Class
 *
 * @author C. Moller <xavier.tnc@gmail.com> - 18 Sep 2018
 *
 * Licensed under the MIT license. Please see LICENSE for more information.
 *
 */
class PayFast
{

  public  $hostname;
  public  $sandboxMode;

  private $debug;
  private $logger;
  private $curlUserAgent;
  private $submitOrderUri;
  private $itnValidateUri;

  private $validHostnames = array (
    'www.payfast.co.za',
    'sandbox.payfast.co.za',
    'w1w.payfast.co.za',
    'w2w.payfast.co.za'
  );

  private $messages = array (
    'PF_ERR_AMOUNT_MISMATCH'   => 'Amount mismatch',
    'PF_ERR_BAD_SOURCE_IP'     => 'Bad source IP address',
    'PF_ERR_CONNECT_FAILED'    => 'Failed to connect to PayFast',
    'PF_ERR_BAD_ACCESS'        => 'Bad access of page',
    'PF_ERR_INVALID_SIGNATURE' => 'Security signature mismatch',
    'PF_ERR_CURL_ERROR'        => 'An error occurred executing cURL',
    'PF_ERR_INVALID_DATA'      => 'The data received is invalid',
    'PF_ERR_UKNOWN'            => 'Unkown error occurred',
    'PF_MSG_OK'                => 'Payment was successful',
    'PF_MSG_FAILED'            => 'Payment has failed',
    'PF_MSG_ERR'               => 'Invalid Message Identifier'
  );

  private $itnData = array (
    'merchant_id'              => null,
    'merchant_key'             => null,
    'return_url'               => null,
    'cancel_url'               => null,
    'notify_url'               => null,
    'name_first'               => 'Test',
    'name_last'                => 'User',
    'email_address'            => 'sbtu01@payfast.co.za',
    'cell_number'              => null,
    'm_payment_id'             => null,
    'amount'                   => null,
    'item_name'                => null,
    'item_description'         => null,
    'custom_int1'              => null,
    'custom_int2'              => null,
    'custom_int3'              => null,
    'custom_int4'              => null,
    'custom_int5'              => null,
    'custom_str1'              => null,
    'custom_str2'              => null,
    'custom_str3'              => null,
    'custom_str4'              => null,
    'custom_str5'              => null,
    'payment_method'           => null  // eft,cc,dd,bc,mp,mc,cd
  );


  private function _array_get(array $array, $key, $default = null)
  {
    return isset($array[$key]) ? $array[$key] : $default;
  }



  protected function _t($messageID)
  {
    return $this->_array_get($this->messages, $messageID?:'PF_MSG_ERR');
  }



  public function __construct($mode, $options = null)
  {
    // Default to 'SANDBOX' mode if $mode is not set to 'LIVE'
    $this->sandboxMode = (strtoupper($mode) !== 'LIVE');

    // Apply optional settings:
    if ( ! is_array($options)) { $options = array(); }

    // Debug Logger
    $this->logger = $this->_array_get($options, 'logger');

    // Log stuff or not?
    $this->debug = $this->_array_get($options, 'debug', false);

    // PF Server Hostname
    $this->hostname = $this->sandboxMode
      ? $this->_array_get($options, 'sandbox_hostname', 'sandbox.payfast.co.za')
      : $this->_array_get($options, 'hostname', 'www.payfast.co.za');

    // CURL User Agent
    $this->curlUserAgent = $this->_array_get($options, 'curl_user_agent',
      'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');

    // PF ITN Data Process URI (Relative)
    $this->itnProcessUri = $this->_array_get($options, 'itn_process_uri',
      '/eng/process');

    // PF ITN Data Validate URI (Relative)
    $this->itnValidateUri = $this->_array_get($options, 'itn_validate_uri',
      '/eng/query/validate');

    // PF Merchant ID
    $this->itnData['merchant_id'] = $this->_array_get($options, 'merchant_id');

    // PF Merchant Key
    $this->itnData['merchant_key'] = $this->_array_get($options, 'merchant_key');

    // PF Return URL
    $this->itnData['return_url'] = $this->_array_get($options, 'return_url');

    // PF Cancel URL
    $this->itnData['cancel_url'] = $this->_array_get($options, 'cancel_url');

    // PF ITN Notify URL
    $this->itnData['notify_url'] = $this->_array_get($options, 'notify_url');

    // PF ITN Hostnames
    if (isset($options['valid_hostnames'])) {
      $this->validHostnames = $options['valid_hostnames'];
    }

    // PF ITN Messages
    if (isset($options['itn_messages'])) {
      $this->messages = $options['itn_messages'];
    }

  }



  protected function log($message)
  {
    if ($this->logger and $this->debug) {
      $this->logger->log($message);
    }
  }



  public function stringifyItnData(array $itnData, $removeEmptyItems = false)
  {
    $this->log('PayFast::stringifyItnData(), removeEmptyItems = ' . ($removeEmptyItems ? 'Yes' : 'No'));

    // Clean up values...
    foreach ($itnData as $key => $val) {
      $itnData[$key] = stripslashes($val);
    }

    // Convert the received ITN data array to a string,
    // while excluding the 'signature' parameter.
    $keyValuePairs = array();
    foreach ($itnData as $key => $val) {
       if ($key != 'signature') {
         if ( ! $removeEmptyItems or strlen($val)) {
           $keyValuePairs[] = $key . '=' . urlencode($val);
         }
       } else {
         $this->log('PayFast::stringifyItnData(), signature = ' . $val);
       }
    }

    $result = trim(implode('&', $keyValuePairs));

    $this->log('PayFast::stringifyItnData(), result = ' . $result);

    return $result;
  }



  public function generateItnSignature($itnDataAsString, $passphrase = null)
  {
    $this->log('PayFast::generateItnSignature(), passphrase = ' . $passphrase);

    $result = $passphrase
      ? md5($itnDataAsString . '&passphrase=' . urlencode($passphrase))
      : md5($itnDataAsString);

    $this->log('PayFast::generateItnSignature(), result = ' . $result);

    return $result;
  }



  public function curlPost($postUrl, $userAgent, $dataAsString)
  {
    $ch = curl_init();

    $this->log('PayFast::curlPost(), postUrl = ' . $postUrl);
    $this->log('PayFast::curlPost(), dataAsString = ' . $dataAsString);

    $curlOptions = array(
      CURLOPT_POST => true,
      CURLOPT_URL => $postUrl,
      CURLOPT_USERAGENT => $userAgent,
      CURLOPT_POSTFIELDS => $dataAsString,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_SSL_VERIFYPEER => 1,
      CURLOPT_RETURNTRANSFER => true,      // Return response as string rather than outputting it
      CURLOPT_HEADER => false              // Don't include header in response
    );

    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec( $ch );            // Execute CURL
    curl_close( $ch );

    $this->log('PayFast::curlPost(), response = ' . $response);

    if ($response === false ) {
       throw new Exception($this->_t('PF_ERR_CURL_ERROR'));
    }

    return $response;
  }



  public function webSocketPost($hostUrl, $actionUri, $dataAsString)
  {
    // Construct Header
    $header = "POST $actionUri HTTP/1.0\r\n";
    $header .= "Host: $hostUrl\r\n";
    $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $header .= 'Content-Length: ' . strlen($dataAsString) . "\r\n\r\n";

    // Connect to server
    $socket = fsockopen('ssl://'. $hostUrl, 443, $errno, $errstr, 10);

    // Send command to server
    fputs($socket, $header . $dataAsString);

    // Read the response from the server
    $response = '';
    $headerDone = false;
    while ( ! feof($socket))
    {
      $line = fgets($socket, 1024);
      // Check if we are finished reading yet
      if (strcmp($line, "\r\n") == 0) {
        $headerDone = true;
      }
      elseif ($headerDone) {
        $response .= $line;
      }
    }

    return $response;
  }



  public function validateRemoteIP(array $validHostnames)
  {
    $allValidItnIPs = array();

    foreach ($validHostnames as $hostname)
    {
      $validIPsForItnHost = gethostbynamel($hostname);
      if ($validIPsForItnHost) {
        $allValidItnIPs = array_merge($allValidItnIPs, $validIPsForItnHost);
      }
    }

    // Remove any duplicate IP's
    $allValidItnIPs = array_unique($allValidItnIPs);

    $remoteIP = $this->_array_get($_SERVER, 'REMOTE_ADDR');

    if ( ! in_array($remoteIP, $allValidItnIPs))
    {
       throw new Exception($this->_t('PF_ERR_BAD_SOURCE_IP'));
    }
  }



  public function validateItnRequestSignature($itnDataAsString, $itnPostedSignature, $passphrase = null)
  {
    $this->log('PayFast::validateItnRequestSignature(), passphrase = ' . $passphrase);
    $this->log('PayFast::validateItnRequestSignature(), itnPostedSignature = ' . $itnPostedSignature);

     $itnSignature = $this->generateItnSignature($itnDataAsString, $passphrase);
     if ($itnSignature != $itnPostedSignature)
     {
        throw new Exception($this->_t('PF_ERR_INVALID_SIGNATURE'));
     }
  }



  public function askPfServerToValidateItnDataRecieved($hostname,
    $itnValidateUri, $curlUserAgent, $itnDataAsString)
  {
    // Use cURL (If it's available)
    if (function_exists('curl_init'))
    {
      $result = $this->curlPost('https://' . $hostname . $itnValidateUri,
        $curlUserAgent, $itnDataAsString);
    }
    else
    {
      $result = $this->webSocketPost($hostname, $itnValidateUri, $itnDataAsString);
    }

    $lines = explode("\n", $result);

    // Get the response from PayFast (VALID or INVALID)
    $result = trim($lines[0]);
    if (strcmp($result, 'VALID') != 0)
    {
      throw new Exception($this->_t('PF_ERR_INVALID_DATA'));
    }

    return $lines;
  }



  public function validateItnRequest(array $itnData, $passphrase = null)
  {
    // Validate the ITN server's IP address
    $this->validateRemoteIP($this->validHostnames);

    // Validate ITN signature
    $itnSignature = $this->_array_get($itnData, 'signature');
    $itnDataAsString = $this->stringifyItnData($itnData, 'removeEmptyItems');
    $this->validateItnRequestSignature($itnDataAsString, $itnSignature, $passphrase);

    // Confirm that the ITN data recieved matches INT data sent by Payfast.
    return $this->askPfServerToValidateItnDataRecieved($this->hostname,
      $this->itnValidateUri, $this->curlUserAgent, $itnDataAsString);
  }



  public function aknowledgeItnRequest()
  {
    // Notify PayFast that information has been received
    header( 'HTTP/1.0 200 OK' );
    flush();
  }



  public function getItnData()
  {
    return $this->itnData;
  }



  public function setItnData(array $itnData)
  {
    $this->itnData = array_merge($this->itnData, $itnData);
  }



  public function getSignature(array $itnData, $removeEmptyItems = false, $passphrase = null)
  {
    $itnDataAsString = $this->stringifyItnData($itnData, $removeEmptyItems);
    return $this->generateItnSignature($itnDataAsString, $passphrase);
  }



  public function getPayButtonHtml()
  {
    return;
  }


}
