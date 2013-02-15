<?php

namespace OAuth\OAuth1;

use \OAuth\OAuth1\Token;
use \OAuth\OAuth1\Token\Request as RequestToken;
use \OAuth\OAuth1\Token\Access as AccessToken;
use \OAuth\OAuth1\Request\Token as TokenRequest;
use \OAuth\OAuth1\Request\Authorize as AuthorizeRequest;
use \OAuth\OAuth1\Request\Access as AccessRequest;
use \OAuth\OAuth1\Consumer;
use \OAuth\OAuth1\Signature;

/**
 * OAuth Provider
 *
 * @package    CodeIgniter/OAuth
 * @category   Provider
 * @author     Phil Sturgeon
 * @copyright  (c) 2012 HappyNinjas Ltd
 * @license    http://philsturgeon.co.uk/code/dbad-license
 */

abstract class Provider
{

    /**
     * @var  string  provider name
     */
    public $name;

    /**
     * @var  string  signature type
     */
    protected $signature = 'HMAC-SHA1';

    /**
     * @var  string  uid key name
     */
    public $uid_key = 'uid';

    /**
     * @var  array  additional request parameters to be used for remote requests
     */
    protected $params = array();

    /**
     * @var  string  default scope (useful if a scope is required for user info)
     */
    protected $scope;
    
    /**
     * @var  string  scope separator, most use "," but some like Google are spaces
     */
    public $scope_seperator = ',';

    protected $token;

    /**
     * Overloads default class properties from the options.
     *
     * Any of the provider options can be set here:
     *
     * Type      | Option        | Description                                    | Default Value
     * ----------|---------------|------------------------------------------------|-----------------
     * mixed     | signature     | Signature method name or object                | provider default
     *
     * @param   array   provider options
     * @return  void
     */
    public function __construct(array $options = NULL)
    {
        if (isset($options['signature'])) {
            // Set the signature method name or object
            $this->signature = $options['signature'];
        }

        if ( ! is_object($this->signature)) {
            // Convert the signature name into an object
            $class = str_replace('-', '', $this->signature);
            $class = "\OAuth\OAuth1\Signature\\$class";
            $this->signature = new $class;
        }

        $this->consumer = new Consumer($options);
    }

    /**
     * Return the value of any protected class variable.
     *
     *     // Get the provider signature
     *     $signature = $provider->signature;
     *
     * @param   string  variable name
     * @return  mixed
     */
    public function __get($key)
    {
        return $this->$key;
    }

    /**
     * Returns the request token URL for the provider.
     *
     *     $url = $provider->url_request_token();
     *
     * @return  string
     */
    abstract public function requestTokenUrl();

    /**
     * Returns the authorization URL for the provider.
     *
     *     $url = $provider->url_authorize();
     *
     * @return  string
     */
    abstract public function authorizeUrl();

    /**
     * Returns the access token endpoint for the provider.
     *
     *     $url = $provider->url_access_token();
     *
     * @return  string
     */
    abstract public function accessTokenUrl();
    
    /**
     * Returns basic information about the user.
     *
     *     $url = $provider->get_user_info();
     *
     * @return  string
     */
    abstract public function getUserInfo(Consumer $consumer, Token $token);

    /**
     * Ask for a request token from the OAuth provider.
     *
     *     $token = $provider->request_token($consumer);
     *
     * @param   Consumer  consumer
     * @param   array           additional request parameters
     * @return  Token_Request
     * @uses    Request_Token
     */
    public function requestToken($redirect_url = null, array $params = NULL)
    {
        $redirect_url = $redirect_url ?: $this->consumer->redirect_url;
        $scope = is_array($this->consumer->scope) ? implode($this->consumer->scope_seperator, $this->consumer->scope) : $this->consumer->scope;

        // Create a new GET request for a request token with the required parameters
        $request = new TokenRequest('GET', $this->requestTokenUrl(), array(
            'oauth_consumer_key' => $this->consumer->client_id,
            'oauth_callback'     => $redirect_url,
            'scope'              => $scope
        ));

        if ($params)
        {
            // Load user parameters
            $request->params($params);
        }

        // Sign the request using only the consumer, no token is available yet
        $request->sign($this->signature, $this->consumer
            ->scope($scope)
            ->callback($redirect_url)
        );

        // Create a response from the request
        $response = $request->execute();

        // Store this token somewhere useful
        return new RequestToken(array(
            'access_token'  => $response->param('oauth_token'),
            'secret' => $response->param('oauth_token_secret'),
        ));
    }

    public function process(callable $process = null, callable $callback = null)
    {
        if ( ! isset($_GET['oauth_token'])) {
            // Get a request token for the consumer
            $token = $this->requestToken();

            // Get the URL to the twitter login page
            $url = $this->authorize($token, array(
                'oauth_callback' => $this->consumer->redirect_url,
            ));

            $process($url, $token);
        } else {
            $token = $callback();

            if ( ! empty($token) AND $token->access_token !== $_REQUEST['oauth_token']) {   
                throw new Exception('OAuth token empty or does not match');
            }

            // Get the verifier
            $verifier = $_REQUEST['oauth_verifier'];

            // Store the verifier in the token
            $token->verifier($verifier);

            // Exchange the request token for an access token
            $this->token = $this->accessToken($token);

            return $this;
        }
    }

    /**
     * Get the authorization URL for the request token.
     *
     *     Response::redirect($provider->authorize_url($token));
     *
     * @param   Token_Request  token
     * @param   array                additional request parameters
     * @return  string
     */
    public function authorize(RequestToken $token, array $params = NULL)
    {
        // Create a new GET request for a request token with the required parameters
        $request = new AuthorizeRequest('GET', $this->authorizeUrl(), array(
            'oauth_token' => $token->access_token,
        ));

        if ($params) {
            // Load user parameters
            $request->params($params);
        }

        return $request->as_url();
    }

    /**
     * Exchange the request token for an access token.
     *
     *     $token = $provider->access_token($consumer, $token);
     *
     * @param   Consumer       consumer
     * @param   Token_Request  token
     * @param   array                additional request parameters
     * @return  Token_Access
     */
    public function accessToken(Consumer $consumer, RequestToken $token, array $params = null)
    {
        // Create a new GET request for a request token with the required parameters
        $request = new AccessRequest('GET', $this->urlAccessToken(), array(
            'oauth_consumer_key' => $consumer->key,
            'oauth_token'        => $token->access_token,
            'oauth_verifier'     => $token->verifier,
        ));

        if ($params)
        {
            // Load user parameters
            $request->params($params);
        }

        // Sign the request using only the consumer, no token is available yet
        $request->sign($this->signature, $consumer, $token);

        // Create a response from the request
        $response = $request->execute();
        
        // Store this token somewhere useful
        return new AccessToken(array(
            'access_token'  => $response->param('oauth_token'),
            'secret' => $response->param('oauth_token_secret'),
            'uid' => $response->param($this->uid_key) ? $response->param($this->uid_key) : get_instance()->input->get_post($this->uid_key),
        ));
    }

} // End Provider