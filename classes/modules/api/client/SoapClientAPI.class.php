<?php
/*$License$*/


/**
 * @package API\SoapClientAPI
 */
class SoapClientAPI {
	protected $url = NULL;
	protected $session_id = NULL;
	protected $session_hash = NULL; //Used to determine if we need to login again because the URL or Session changed.
	protected $class_factory = NULL;
	protected $namespace = 'urn:api';
	protected $protocol_version = 1;

	protected $soap_obj = NULL; //Persistent SOAP object.

	/**
	 * SoapClientAPI constructor.
	 * @param null $class
	 * @param null $url
	 * @param string $session_id UUID
	 */
	function __construct( $class = NULL, $url = NULL, $session_id = NULL) {
		global $URL, $SESSION_ID;

		ini_set('default_socket_timeout', 3600);

		if ( $url == '' ) {
			$url = $URL;
		}

		if ( $session_id == '' ) {
			$session_id = $SESSION_ID;
		}

		$this->setURL( $url );
		$this->setSessionId( $session_id );
		$this->setClass( $class );

		return TRUE;
	}

	/**
	 * @return SoapClient
	 */
	function getSoapClientObject() {
		global $BASIC_AUTH_USER, $BASIC_AUTH_PASSWORD;

		if ( $this->session_id != '' ) {
			$url_pieces[] = 'SessionID='. $this->session_id;
		}

		$url_pieces[] = 'Class='. $this->class_factory;

		if ( strpos( $this->url, '?' ) === FALSE ) {
			$url_separator = '?';
		} else {
			$url_separator = '&';
		}

		$url = $this->url.$url_separator.'v='. $this->protocol_version .'&'. implode('&', $url_pieces);

		//Try to maintain existing SOAP object as there could be cookies associated with it.
		if ( !is_object( $this->soap_obj ) ) {
			$retval = new SoapClient( NULL, array(
												  'location'    => $url,
												  'uri'         => $this->namespace,
												  'encoding'    => 'UTF-8',
												  'style'       => SOAP_RPC,
												  'use'         => SOAP_ENCODED,
												  'login'       => $BASIC_AUTH_USER,
												  'password'    => $BASIC_AUTH_PASSWORD,
												  //'connection_timeout' => 120,
												  //'request_timeout' => 3600,
												  'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
												  'trace'       => 1,
												  'exceptions'  => 0
										  )
			);

			$this->soap_obj = $retval;
		} else {
			$retval = $this->soap_obj;
			$retval->__setLocation( $url );
		}

		return $retval;
	}

	/**
	 * @param $url
	 * @return bool
	 */
	function setURL( $url ) {
		if ( $url != '' ) {
			$this->url = $url;
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * @param $value
	 * @return bool
	 */
	function setSessionID( $value ) {
		if ( $value != '' ) {
			global $SESSION_ID;
			$this->session_id = $SESSION_ID = $value;
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * @param $value
	 * @return bool
	 */
	function setClass( $value ) {
		$this->class_factory = trim($value);

		return TRUE;
	}

	/**
	 * Use the SessionHash to ensure the URL for the session doesn't get changed out from under us without re-logging in.
	 * @param $url
	 * @param string $session_id UUID
	 * @return string
	 */
	function calcSessionHash( $url, $session_id ) {
		return md5( trim($url) . trim($session_id) );
	}

	/**
	 * @return null
	 */
	function getSessionHash() {
		return $this->session_hash;
	}

	/**
	 * @return bool
	 */
	private function setSessionHash() {
		global $SESSION_HASH;
		$this->session_hash = $SESSION_HASH = $this->calcSessionHash( $this->url, $this->session_id );
		return TRUE;
	}

	/**
	 * @param $result
	 * @return mixed
	 */
	function isFault( $result ) {
		return $this->getSoapClientObject()->is_soap_fault( $result );
	}

	/**
	 * @param $user_name
	 * @param null $password
	 * @param string $type
	 * @return bool
	 */
	function Login( $user_name, $password = NULL, $type = 'USER_NAME' ) {
		//Check to see if we are currently logged in as the same user already?
		global $SESSION_ID, $SESSION_HASH;
		if ( $SESSION_ID != '' AND $SESSION_HASH == $this->calcSessionHash( $this->url, $SESSION_ID ) ) { //AND $this->isLoggedIn() == TRUE
			//Already logged in, skipping unnecessary new login procedure.
			return TRUE;
		}

		$this->session_id = $this->session_hash = NULL; //Don't set old session ID on URL.
		$retval = $this->getSoapClientObject()->Login( $user_name, $password, $type );
		if ( is_soap_fault( $retval ) ) {
			trigger_error('SOAP Fault: (Code: '. $retval->faultcode .', String: '. $retval->faultstring .') - Request: '. $this->getSoapClientObject()->__getLastRequest() .' Response: '. $this->getSoapClientObject()->__getLastResponse(), E_USER_NOTICE);
			return FALSE;
		}

		if ( !is_array($retval) AND $retval != FALSE ) {
			$this->setSessionID( $retval );
			$this->setSessionHash();
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * @return mixed
	 */
	function isLoggedIn() {
		$old_class = $this->class_factory;
		$this->setClass('Authentication');
		$retval = $this->getSoapClientObject()->isLoggedIn();
		$this->setClass( $old_class );
		unset($old_class);

		return $retval;
	}

	/**
	 * @return mixed
	 */
	function Logout() {
		$this->setClass('Authentication');
		$retval = $this->getSoapClientObject()->Logout();
		if ( $retval == TRUE ) {
			global $SESSION_ID, $SESSION_HASH;
			$SESSION_ID = $SESSION_HASH = FALSE;
			$this->session_id = $this->session_hash = NULL;
		}

		return $retval;
	}

	/**
	 * @param $function_name
	 * @param array $args
	 * @return bool|SoapClientAPIReturnHandler
	 */
	function __call( $function_name, $args = array() ) {
		if ( is_object( $this->getSoapClientObject() ) ) {
			$retval = call_user_func_array(array($this->getSoapClientObject(), $function_name), $args);

			if ( is_soap_fault( $retval ) ) {
				trigger_error('SOAP Fault: (Code: '. $retval->faultcode .', String: '. $retval->faultstring .') - Request: '. $this->getSoapClientObject()->__getLastRequest() .' Response: '. $this->getSoapClientObject()->__getLastResponse(), E_USER_NOTICE);

				return FALSE;
			}

			return new SoapClientAPIReturnHandler( $function_name, $args, $retval );
		}

		return FALSE;
	}
}

/**
 * @package API\SoapClientAPI
 */
class SoapClientAPIReturnHandler {
	/*
	'api_retval' => $retval,
	'api_details' => array(
					'code' => $code,
					'description' => $description,
					'record_details' => array(
											'total' => $validator_stats['total_records'],
											'valid' => $validator_stats['valid_records'],
											'invalid' => ($validator_stats['total_records']-$validator_stats['valid_records'])
											),
					'details' =>  $details,
					)
	*/
	protected $function_name = NULL;
	protected $args = NULL;
	protected $result_data = FALSE;

	/**
	 * SoapClientAPIReturnHandler constructor.
	 * @param $function_name
	 * @param $args
	 * @param $result_data
	 */
	function __construct( $function_name, $args, $result_data ) {
		$this->function_name = $function_name;
		$this->args = $args;
		$this->result_data = $result_data;

		return TRUE;
	}

	/**
	 * @return bool
	 */
	function getResultData() {
		return $this->result_data;
	}

	/**
	 * @return null
	 */
	function getFunction() {
		return $this->function_name;
	}

	/**
	 * @return null
	 */
	function getArgs() {
		return $this->args;
	}

	/**
	 * @return string
	 */
	function __toString() {
		$eol = "<br>\n";

		$output = array();
		$output[] = '=====================================';
		$output[] = 'Function: '. $this->getFunction() .'()';
		if ( is_object( $this->getArgs() ) OR is_array( $this->getArgs() ) ) {
			$output[] = 'Args: '. count( $this->getArgs() );
		} else {
			$output[] = 'Args: '. $this->getArgs();
		}
		$output[] = '-------------------------------------';
		$output[] = 'Returned:';
		$output[] = ( $this->isValid() === TRUE ) ? 'IsValid: YES' : 'IsValid: NO';
		if ( $this->isValid() === TRUE ) {
			$output[] = 'Return Value: '. $this->getResult();
		} else {
			$output[] = 'Code: '. $this->getCode();
			$output[] = 'Description: '. $this->getDescription();
			$output[] = 'Details: ';

			$details = $this->getDetails();
			if ( is_array($details) ) {
				foreach( $details as $row => $detail ) {
					$output[] = 'Row: '. $row;
					foreach( $detail as $field => $msgs ) {
						$output[] = '--Field: '. $field;
						foreach( $msgs as $key => $msg ) {
							$output[] = '----Message: '. $msg;
						}
					}
				}
			}
		}
		$output[] = '=====================================';
		$output[] = '';

		return implode( $eol, $output );
	}

	/**
	 * @return bool
	 */
	function isValid() {
		if ( isset($this->result_data['api_retval']) ) {
			return (bool)$this->result_data['api_retval'];
		}

		return TRUE;
	}

	/**
	 * @return bool
	 */
	function isError() { //Opposite of isValid()
		if ( isset($this->result_data['api_retval']) ) {
			if ( $this->result_data['api_retval'] === FALSE ) {
				return TRUE;
			} else {
				return FALSE;
			}
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getCode() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['code']) ) {
			return $this->result_data['api_details']['code'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getDescription() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['description']) ) {
			return $this->result_data['api_details']['description'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getDetails() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['details']) ) {
			return $this->result_data['api_details']['details'];
		}

		return FALSE;
	}

	/**
	 * @return bool|string
	 */
	function getDetailsDescription() {
		$details = $this->getDetails();
		if ( is_array( $details ) ) {
			$retval = array();

			foreach( $details as $key => $row_details ) {
				foreach ( $row_details as $field => $field_details ) {
					foreach( $field_details as $detail ) {
						$retval[] = '['. $field .'] '. $detail;
					}
				}
			}

			return implode( ' ', $retval );
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getRecordDetails() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['record_details']) ) {
			return $this->result_data['api_details']['record_details'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getTotalRecords() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['record_details']) AND isset($this->result_data['api_details']['record_details']['total_records']) ) {
			return $this->result_data['api_details']['record_details']['total_records'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getValidRecords() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['record_details']) AND isset($this->result_data['api_details']['record_details']['valid_records']) ) {
			return $this->result_data['api_details']['record_details']['valid_records'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getInValidRecords() {
		if ( isset($this->result_data['api_details']) AND isset($this->result_data['api_details']['record_details']) AND isset($this->result_data['api_details']['record_details']['invalid_records']) ) {
			return $this->result_data['api_details']['record_details']['invalid_records'];
		}

		return FALSE;
	}

	/**
	 * @return bool
	 */
	function getResult() {
		if ( isset($this->result_data['api_retval']) ) {
			return $this->result_data['api_retval'];
		} else {
			return $this->result_data;
		}
	}
}
?>
