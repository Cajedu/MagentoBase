<?php
/*!
* HybridAuth
* http://hybridauth.sourceforge.net | https://github.com/hybridauth/hybridauth
*  (c) 2009-2015 HybridAuth authors | hybridauth.sourceforge.net/licenses.html
*/

namespace Mageplaza\SocialLogin\Model\Providers;

use Exception;
use Hybrid_Auth;
use Hybrid_Provider_Model;
use Hybrid_User_Profile;
use Mageplaza\SocialLogin\Model\Providers\Apple\AppleOAuth2Client;

/**
 * Hybrid_Providers_Amazon provider adapter based on OAuth2 protocol
 *
 * added by skyverge | https://github.com/skyverge
 *
 * The Provider is very similar to standard Oauth2 providers with a few differences:
 * - it sets the Content-Type header explicitly to application/x-www-form-urlencoded
 *   as required by Amazon
 * - it uses a custom OAuth2Client, because the built-in one does not use http_build_query()
 *   to set curl POST params, which causes cURL to set the Content-Type to multipart/form-data
 *
 * @property OAuth2Client $api
 */
class Apple extends Hybrid_Provider_Model
{
    // default permissions
    public $scope = 'name email';
    
    public $id_token='';

    /**
     * IDp wrappers initializer
     *
     * @throws Exception
     */
    function initialize()
    {
        
         if (!$this->config["keys"]["id"] || !$this->config["keys"]["secret"]) {
      throw new Exception("Your application id and secret are required in order to connect to {$this->providerId}.", 4);
    }

    // override requested scope
    if (isset($this->config["scope"]) && !empty($this->config["scope"])) {
      $this->scope = $this->config["scope"];
    }

    // include OAuth2 client
    require_once Hybrid_Auth::$config["path_libraries"] . "OAuth/OAuth2Client.php";

    // create a new OAuth2 client instance
    $this->api = new AppleOAuth2Client($this->config["keys"]["id"], $this->config["keys"]["secret"], $this->endpoint, $this->compressed);

        // create a new OAuth2 client instance
        

        $this->api->api_base_url  = 'https://appleid.apple.com/';
        $this->api->authorize_url = 'https://appleid.apple.com/auth/authorize';
        $this->api->token_url     = 'https://appleid.apple.com/auth/token';

        $this->api->curl_header = ['Content-Type: application/x-www-form-urlencoded'];

        // If we have an access token, set it
          if ($this->token("access_token")) {
      $this->api->access_token = $this->token("access_token");
      $this->api->refresh_token = $this->token("refresh_token");
      $this->api->access_token_expires_in = $this->token("expires_in");
      $this->api->access_token_expires_at = $this->token("expires_at");
      $this->api->id_token = $this->token("id_token");
    }

    // Set curl proxy if exist
    if (isset(Hybrid_Auth::$config["proxy"])) {
      $this->api->curl_proxy = Hybrid_Auth::$config["proxy"];
    }
    }
    /**
   * {@inheritdoc}
   */
    function loginBegin() {
        $_SESSION['state'] = bin2hex(random_bytes(5));
    // redirect the user to the provider authentication url
    Hybrid_Auth::redirect($this->api->authorizeUrl(array("response_mode" => "form_post","state" => $_SESSION['state'] ,"scope" => $this->scope)));
  }
    function loginFinish() {
    $error = (array_key_exists('error', $_REQUEST)) ? $_REQUEST['error'] : "";

    // check for errors
    if ($error) {
      throw new Exception("Authentication failed! {$this->providerId} returned an error: $error", 5);
    }

    // try to authenticate user
    $code = (array_key_exists('code', $_REQUEST)) ? $_REQUEST['code'] : "";

    try {
      $this->api->authenticate($code);
    } catch (Exception $e) {
      throw new Exception("User profile request failed! {$this->providerId} returned an error: " . $e->getMessage(), 6);
    }

    // check if authenticated
    if (!$this->api->access_token) {
      throw new Exception("Authentication failed! {$this->providerId} returned an invalid access token.", 5);
    }

    // store tokens
    $this->token("access_token", $this->api->access_token);
    $this->token("refresh_token", $this->api->refresh_token);
    $this->token("expires_in", $this->api->access_token_expires_in);
    $this->token("expires_at", $this->api->access_token_expires_at);
    $this->token("id_token", $this->api->id_token);
    $claims = explode('.', $this->api->id_token)[1];
    $this->id_token = json_decode(base64_decode($claims));

    // set user connected locally
    $this->setUserConnected();
  }

    /**
     * load the user profile from the IDp api client
     *
     * @return Hybrid_User_Profile
     * @throws Exception
     */
    function getUserProfile()
    {
        
        if ($this->id_token != '') {
            throw new Exception("User profile request failed! {$this->providerId} returned an invalid response.", 6);
        }
        $data=$this->idToken;
        $this->user->profile->identifier  = $data->sub;
        $this->user->profile->email       = $data->email_verified;
        $this->user->profile->displayName = $data->name;

        return $this->user->profile;
    }
}
