<?php
/**
 * OAuth 2.0 Refresh token grant
 *
 * @package     league/oauth2-server
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) PHP League of Extraordinary Packages
 * @license     http://mit-license.org/
 * @link        http://github.com/php-loep/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use League\OAuth2\Server\Request;
use League\OAuth2\Server\Authorization;
use League\OAuth2\Server\Exception;
use League\OAuth2\Server\Util\SecureKey;
use League\OAuth2\Server\Storage\SessionInterface;
use League\OAuth2\Server\Storage\ClientInterface;
use League\OAuth2\Server\Storage\ScopeInterface;
use League\OAuth2\Server\Entities\RefreshToken as RT;
use League\OAuth2\Server\Entities\AccessToken;
use League\OAuth2\Server\Entities\Session;
use League\OAuth2\Server\Exception\ClientException;

/**
 * Referesh token grant
 */
class RefreshToken extends AbstractGrant
{
    /**
     * {@inheritdoc}
     */
    protected $identifier = 'refresh_token';

    /**
     * Refresh token TTL (default = 604800 | 1 week)
     * @var integer
     */
    protected $refreshTokenTTL = 604800;

    /**
     * Set the TTL of the refresh token
     * @param int $refreshTokenTTL
     * @return void
     */
    public function setRefreshTokenTTL($refreshTokenTTL)
    {
        $this->refreshTokenTTL = $refreshTokenTTL;
    }

    /**
     * Get the TTL of the refresh token
     * @return int
     */
    public function getRefreshTokenTTL()
    {
        return $this->refreshTokenTTL;
    }

    /**
     * {@inheritdoc}
     */
    public function completeFlow()
    {
        $clientId = $this->server->getRequest()->request->get('client_id', null);
        if (is_null($clientId)) {
            throw new Exception\ClientException(
                sprintf($this->server->getExceptionMessage('invalid_request'), 'client_id'),
                0
            );
        }

        $clientSecret = $this->server->getRequest()->request->get('client_secret', null);
        if (is_null($clientSecret)) {
            throw new Exception\ClientException(
                sprintf($this->server->getExceptionMessage('invalid_request'), 'client_secret'),
                0
            );
        }

        // Validate client ID and client secret
        $client = $this->server->getStorage('client')->getClient(
            $clientId,
            $clientSecret,
            null,
            $this->getIdentifier()
        );

        if ($client === null) {
            throw new ClientException(Authorization::getExceptionMessage('invalid_client'), 8);
        }

        $oldRefreshTokenParam = $this->server->getRequest()->request->get('refresh_token', null);
        if ($oldRefreshTokenParam === null) {
            throw new Exception\ClientException(
                sprintf($this->server->getExceptionMessage('invalid_request'), 'refresh_token'),
                0
            );
        }

        // Validate refresh token
        $oldRefreshToken = $this->server->getStorage('refresh_token')->getToken($oldRefreshTokenParam);

        if (($oldRefreshToken instanceof RT) === false) {
            throw new Exception\ClientException($this->server->getExceptionMessage('invalid_refresh'), 0);
        }

        $oldAccessToken = $oldRefreshToken->getAccessToken();

        // Get the scopes for the original session
        $session = $oldAccessToken->getSession();
        $scopes = $session->getScopes();

        // Get and validate any requested scopes
        $requestedScopesString = $this->server->getRequest()->request->get('scope', '');
        $requestedScopes = $this->validateScopes($requestedScopesString);

        // If no new scopes are requested then give the access token the original session scopes
        if (count($requestedScopes) === 0) {
            $newScopes = $scopes;
        } else {
            // The OAuth spec says that a refreshed access token can have the original scopes or fewer so ensure
            //  the request doesn't include any new scopes

            foreach ($requestedScopes as $requestedScope) {
                // if ()
            }

            $newScopes = $requestedScopes;
        }

        // Generate a new access token and assign it the correct sessions
        $newAccessToken = new AccessToken();
        $newAccessToken->setToken(SecureKey::make());
        $newAccessToken->setExpireTime($this->server->getAccessTokenTTL() + time());
        $newAccessToken->setSession($session);

        foreach ($newScopes as $newScope) {
            $newAccessToken->associateScope($newScope);
        }

        // Expire the old token and save the new one
        $oldAccessToken->expire($this->server->getStorage('access_token'));
        $newAccessToken->save($this->server->getStorage('access_token'));

        $response = [
            'access_token'  =>  $newAccessToken->getToken(),
            'token_type'    =>  'Bearer',
            'expires'       =>  $newAccessToken->getExpireTime(),
            'expires_in'    =>  $this->server->getAccessTokenTTL()
        ];

        // Expire the old refresh token
        $oldRefreshToken->expire($this->server->getStorage('refresh_token'));

        // Generate a new refresh token
        $newRefreshToken = new RT();
        $newRefreshToken->setToken(SecureKey::make());
        $newRefreshToken->setExpireTime($this->getRefreshTokenTTL() + time());
        $newRefreshToken->setAccessToken($newAccessToken);
        $newRefreshToken->save($this->server->getStorage('refresh_token'));

        $response['refresh_token'] = $newRefreshToken->getToken();

        return $response;
    }
}
