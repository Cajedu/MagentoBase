<?php
/**
 * HybridAuth
 * http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
 * (c) 2009-2015, HybridAuth authors | http://hybridauth.sourceforge.net/licenses.html
 */

namespace Mageplaza\SocialLogin\Model\Providers\Apple;

use Exception;
use Hybrid_Logger;
use OAuth2Client;
use StdClass;

/**
 * A service client for the Amazon ID OAuth 2 flow.
 *
 * The sole purpose of this subclass is to make sure the POST params
 * for cURL are provided as an urlencoded string rather than an array.
 * This is because Amazon requires COntent-Type header to be application/x-www-form-urlencoded,
 * which cURL overrides to multipart/form-data when POST fields are provided as an array
 *
 * The only difference from Oauth2CLient in authenticate() method is http_build_query()
 * wrapped around $params. request() and parseRequestResult() methods are exact copies
 * from Oauth2Client. They are copied here because private scope does not allow calling them
 * from subclass.
 *
 * @link http://stackoverflow.com/questions/5224790/curl-post-format-for-curlopt-postfields
 */
class AppleOAuth2Client extends OAuth2Client
{
     public $id_token = '';
    /**
     * @param $code
     *
     * @return mixed|StdClass
     * @throws Exception
     */
    public function authenticate($code)
    {
        $params = [
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirect_uri,
            'code'          => $code,
        ];

        $response = $this->request($this->token_url, http_build_query($params), $this->curl_authenticate_method);

        $response = $this->parseRequestResult($response);

        if (!$response || !isset($response->access_token)) {
            throw new Exception('The Authorization Service has return: ' . $response->error);
        }

        if (isset($response->access_token)) {
            $this->access_token = $response->access_token;
        }

        if (isset($response->refresh_token)) {
            $this->refresh_token = $response->refresh_token;
        }

        if (isset($response->expires_in)) {
            $this->access_token_expires_in = $response->expires_in;
        }

        // calculate when the access token expire
        if (isset($response->expires_in)) {
            $this->access_token_expires_at = time() + $response->expires_in;
        }
         if (isset($response->id_token)) {
            $this->id_token = $response->id_token;
        }

        return $response;
    }
}
