<?php namespace Pusher;

/**
 * Pusher PHP Library
 * PHP library for the Pusher API.
 * See the README for usage information: https://github.com/pusher/pusher-php-server
 *
 * @package     Pusher
 * @copyright   2011,   Squeeks
 * @licence     http://www.opensource.org/licenses/mit-license.php  MIT
 * @author      Paul44          <http://github.com/Paul44>
 * @author      Ben Pickles     <http://github.com/benpickles>
 * @author      MasterCoding    <http://www.mastercoding.nl>
 * @author      Alias14         <mali0037@gmail.com>
 * @author      Max Williams    <max@pusher.com>
 * @author      Zack Kitzmiller <delicious@zackisamazing.com>
 * @author      Andrew Bender   <igothelp@gmail.com>
 * @author      Phil Leggetter  <phil@leggetter.co.uk>
 * @author      Mohammad Gufran <me@gufran.me>
 */

use Pusher\Interfaces\LoggerInterface;
use Pusher\Exceptions\PusherException;

class Pusher
{

	/**
	 *
	 */
	private function log( $msg ) {
		if( is_null( $this->logger ) == false ) {
			$this->logger->log( 'Pusher: ' . $msg );
		}
	}


	/**
	* Trigger an event by providing event name and payload. 
	* Optionally provide a socket ID to exclude a client (most likely the sender).
	*
	* @param array $channel An array of channel names to publish the event on.
	* @param string $event
	* @param mixed $data Event data
	* @param int $socket_id [optional]
	* @param bool $debug [optional]
	* @return bool|string
	*/
	public function trigger( $channels, $event, $data, $socket_id = null, $debug = false, $already_encoded = false )
	{
		if( is_string( $channels ) === true ) {
			$this->log( '->trigger received string channel "' . $channels . '". Converting to array.' );
			$channels = array( $channels );
		}

		if( count( $channels ) > 100 ) {
			throw new PusherException('An event can be triggered on a maximum of 100 channels in a single call.');
		}

		$query_params = array();
		
		$s_url = $this->settings['url'] . '/events';		
		
		$data_encoded = $already_encoded ? $data : json_encode( $data );

		$post_params = array();
		$post_params[ 'name' ] = $event;
		$post_params[ 'data' ] = $data_encoded;
		$post_params[ 'channels' ] = $channels;

		if ( $socket_id !== null )
		{
			$post_params[ 'socket_id' ] = $socket_id;
		}

		$post_value = json_encode( $post_params );

		$query_params['body_md5'] = md5( $post_value );

		$ch = $this->create_curl( $s_url, 'POST', $query_params );

		$this->log( 'trigger POST: ' . $post_value );

		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_value );

		$response = $this->exec_curl( $ch );

		if ( $response[ 'status' ] == 200 && $debug == false )
		{
			return true;
		}
		elseif ( $debug == true || $this->settings['debug'] == true )
		{
			return $response;
		}
		else
		{
			return false;
		}

	}
	
	/**
	  *	Fetch channel information for a specific channel.
	  *
	  * @param string $channel The name of the channel
	  * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
	  *	@return object
	  */
	public function get_channel_info($channel, $params = array() )
	{
		$response = $this->get( '/channels/' . $channel, $params );
		
		if( $response[ 'status' ] == 200)
		{
			$response = json_decode( $response[ 'body' ] );
		}
		else
		{
			$response = false;
		}
		
		return $response;
	}
	
	/**
	 * Fetch a list containing all channels
	 * 
	 * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
	 *
	 * @return array
	 */
	public function get_channels($params = array())
	{
		$response = $this->get( '/channels', $params );
		
		if( $response[ 'status' ] == 200)
		{
			$response = json_decode( $response[ 'body' ] );
			$response->channels = get_object_vars( $response->channels );
		}
		else
		{
			$response = false;
		}
		
		return $response;
	}

	/**
	 * GET arbitrary REST API resource using a synchronous http client.
   * All request signing is handled automatically.
   *  
   * @param string path Path excluding /apps/APP_ID
   * @param params array API params (see http://pusher.com/docs/rest_api)
   *
   * @return See Pusher API docs
	 */
	public function get( $path, $params = array() ) {
		$s_url = $this->settings['url'] . $path;	

		$ch = $this->create_curl( $s_url, 'GET', $params );

		$response = $this->exec_curl( $ch );
		
		if( $response[ 'status' ] == 200)
		{
			$response[ 'result' ] = json_decode( $response[ 'body' ], true );
		}
		else
		{
			$response = false;
		}
		
		return $response;
	}


	/**
	* Creates a presence signature (an extension of socket signing)
	*
	* @param int $socket_id
	* @param string $user_id
	* @param mixed $user_info
	* @return string
	*/
	public function presence_auth( $channel, $socket_id, $user_id, $user_info = false )
	{

		$user_data = array( 'user_id' => $user_id );
		if($user_info == true)
		{
			$user_data['user_info'] = $user_info;
		}

		return $this->socket_auth($channel, $socket_id, json_encode($user_data) );
	}

    /**
     * Current version of Pusher library
     */
    const VERSION = '3.0.0';

    /**
     * @var bool
     */
    protected $debug;

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var string
     */
    protected $authKey;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var int
     */
    protected $appId;

    /**
     * @var LoggerInterface
     */
    private $logger = null;

    /**
     * @var Client
     */
    private $client;

    /**
     * Initializes a new Pusher instance with key, secret, app ID and channel.
     * You can optionally supply an array of configuration to alter functionality.
     * Supported keys in array:
     * <code>
     *     array(
     *       'debug'    => false,                   // Enable or disable debugging
     *       'host'     => 'api.pusherapp.com',     // Change the host server URL
     *       'secured'  => true,                    // Force https instead of http
     *       'port'     => 80,                      // Port to connect
     *       'timeout'  => 30                       // Timeout in seconds
     *     )
     * </code>
     *
     * @param string          $authKey
     * @param string          $secret
     * @param string          $appId
     * @param array           $config
     * @param LoggerInterface $logger [optional]
     * @param Client          $client [Optional]
     */
    public function __construct($authKey, $secret, $appId, array $config = array(), LoggerInterface $logger = null, Client $client = null)
    {
        $config = $this->resolveConfig($config);

        $this->authKey = $authKey;
        $this->secret = $secret;
        $this->appId = $appId;
        $this->logger = $logger;
        $this->debug = $config['debug'];
        $this->url = '/apps/' . $appId;

        $protocol = $config['secured'] ? 'https' : 'http';

        if(is_null($client))
        {
            $this->client = new Client($protocol . '://' . $config['host'], $config['port'], $authKey, $secret, $config['timeout']);
        }
        else
        {
            $this->client = $client;
        }
    }

    /**
     * Set a logger to be informed of internal log messages.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    /**
     * create a socket signature
     *
     * @param      $channel
     * @param      $socketId
     * @param string $customData
     * @return string
     */
    public function socketAuth($channel, $socketId, $customData = null)
    {
        $this->log('Pusher::socketAuth() creating socket authorization hash for channel [' . $channel . ']');

        if ($customData)
        {
            $signature = hash_hmac('sha256', $socketId . ':' . $channel . ':' . $customData, $this->secret, false);
        }
        else
        {
            $signature = hash_hmac('sha256', $socketId . ':' . $channel, $this->secret, false);
        }

        $signature = array('auth' => $this->authKey . ':' . $signature);

        if ($customData)
        {
            $signature['channel_data'] = $customData;
        }

        return json_encode($signature);
    }
    /**
     * @param $config
     * @return array
     */
    private function resolveConfig($config)
    {
        $defaultConfig = array(
            'debug'    => false,
            'host'     => 'api.pusherapp.com',
            'secured'  => true,
            'port'     => 80,
            'timeout'  => 30
        );

        return $defaultConfig + $config;
    }
}
