<?php
	class GitHub_WebHook
	{
		private $EventType;
		private $Payload;
		private $RawPayload;
		
		/**
		 * Validates and processes current request
		 */
		public function ProcessRequest( )
		{
			if( !array_key_exists( 'HTTP_X_GITHUB_EVENT', $_SERVER ) )
			{
				throw new Exception( 'Missing event header.' );
			}
			
			$this->EventType = $_SERVER[ 'HTTP_X_GITHUB_EVENT' ];
			
			if ( preg_match( '/^[a-z_]+$/', $this->EventType ) !== 1 )
			{
				throw new Exception( 'Invalid event header.' );
			}
			
			if( !array_key_exists( 'REQUEST_METHOD', $_SERVER ) || $_SERVER[ 'REQUEST_METHOD' ] !== 'POST' )
			{
				throw new Exception( 'Invalid request method.' );
			}
			
			if( !array_key_exists( 'CONTENT_TYPE', $_SERVER ) )
			{
				throw new Exception( 'Missing content type.' );
			}
			
			$ContentType = $_SERVER[ 'CONTENT_TYPE' ];
			
			if( $ContentType === 'application/x-www-form-urlencoded' )
			{
				if( !array_key_exists( 'payload', $_POST ) )
				{
					throw new Exception( 'Missing payload.' );
				}
				
				$this->RawPayload = $_POST[ 'payload' ];
			}
			else if( $ContentType === 'application/json' )
			{
				$this->RawPayload = file_get_contents( 'php://input' );
			}
			else
			{
				throw new Exception( 'Unknown content type.' );
			}
			
			$this->Payload = json_decode( $this->RawPayload );
			
			if( $this->Payload === null )
			{
				throw new Exception( 'Failed to decode JSON: ' .
					function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error()
				);
			}
			
			if( !isset( $this->Payload->repository ) )
			{
				if( !isset( $this->Payload->organization ) )
				{
					throw new Exception( 'Missing repository information.' );
				}
				
				// This is a silly hack to handle org-only events
				$this->Payload->repository = (object)array(
					// Add "/repositories" because repo matching code would expect a "<org>/<repo>" format
					'full_name' => $this->Payload->organization->login . '/repositories',
					'name' => 'org: ' . $this->Payload->organization->login,
					'owner' => (object)array(
						'name' => $this->Payload->organization->login,
						'login' => $this->Payload->organization->login,
					),
				);
			}
			
			return true;
		}
		
		/**
		 * Optional function to check if request came from GitHub's IP range.
		 *
		 * @return bool
		 */
		public function ValidateIPAddress( )
		{
			if( !array_key_exists( 'REMOTE_ADDR', $_SERVER ) )
			{
				throw new Exception( 'Missing remote address.' );
			}
			
			$Remote = ip2long( $_SERVER[ 'REMOTE_ADDR' ] );
			
			// https://api.github.com/meta
			$Addresses =
			[
				[ '192.30.252.0',    22 ],
				[ '185.199.108.0',   22 ],
				[ '140.82.112.0',    20 ],
			];
			
			foreach( $Addresses as $CIDR )
			{
				$Base = ip2long( $CIDR[ 0 ] );
				$Mask = pow( 2, ( 32 - $CIDR[ 1 ] ) ) - 1;
				
				if( $Base === ( $Remote & ~$Mask ) )
				{
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Optional function to check if HMAC hex digest of the payload matches GitHub's.
		 *
		 * @return bool
		 */
		public function ValidateHubSignature( $SecretKey )
		{
			if( !array_key_exists( 'HTTP_X_HUB_SIGNATURE', $_SERVER ) )
			{
				throw new Exception( 'Missing X-Hub-Signature header. Did you configure secret token in hook settings?' );
			}
			
			$KnownAlgo = 'sha1';
			$CalculatedHash = $KnownAlgo . '=' . hash_hmac( $KnownAlgo, $this->RawPayload, $SecretKey, false );
			
			return hash_equals( $CalculatedHash, $_SERVER[ 'HTTP_X_HUB_SIGNATURE' ] );
		}
		
		/**
		 * Returns event type
		 * See https://developer.github.com/webhooks/#events
		 *
		 * @return string
		 */
		public function GetEventType( )
		{
			return $this->EventType;
		}
		
		/**
		 * Returns decoded payload
		 *
		 * @return array
		 */
		public function GetPayload( )
		{
			return $this->Payload;
		}
		
		/**
		 * Returns full name of the repository
		 *
		 * @return string
		 */
		public function GetFullRepositoryName( )
		{
			if( isset( $this->Payload->repository->full_name ) )
			{
				return $this->Payload->repository->full_name;
			}
			
			return sprintf( '%s/%s', $this->Payload->repository->owner->name, $this->Payload->repository->name );
		}
	}
