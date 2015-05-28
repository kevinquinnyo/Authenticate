<?php
namespace Users\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Component\CookieComponent;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;

/**
 * An authentication adapter for AuthComponent
 *
 * Provides the ability to authenticate using COOKIE
 *
 * ```
 *    $this->Auth->config('authenticate', [
 *        'Authenticate.Cookie' => [
 *            'fields' => [
 *                'username' => 'username',
 *                'password' => 'password'
 *             ],
 *            'tokenCreated' => false,
 *            'userModel' => 'Users',
 *            'scope' => ['Users.active' => 1],
 *            'crypt' => 'aes',
 *            'cookie' => [
 *                'name' => 'RememberMe',
 *                'time' => '+2 weeks',
 *            ]
 *        ]
 *    ]);
 * ```
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */
class CookieAuthenticate extends BaseAuthenticate
{

    /**
     * Events supported by this class.
     *
     * @return array
     */
    public function implementedEvents()
    {
        return [
            'Auth.logout' => 'logout',
        ];
    }

    /**
     * Constructor
     *
     * @param \Cake\Controller\ComponentRegistry $registry The Component registry
     *   used on this request.
     * @param array $config Array of config to use.
     */
    public function __construct(ComponentRegistry $registry, $config)
    {
        $this->_registry = $registry;

        $this->config([
            'cookie' => [
                'name' => 'RememberMe',
                'expires' => '+2 weeks'
            ],
            'crypt' => 'aes'
        ]);

        $this->config($config);
    }

    /**
     * Authenticates the identity contained in the cookie.  Will use the
     * `userModel` config, and `fields` config to find COOKIE data that is used
     * to find a matching record in the model specified by `userModel`. Will return
     * false if there is no cookie data, either username or password is missing,
     * or if the scope conditions have not been met.
     *
     * @param Request $request The unused request object.
     * @return mixed False on login failure. An array of User data on success.
     * @throws \RuntimeException If CookieComponent is not loaded.
     */
    public function getUser(Request $request)
    {
        if (!isset($this->_registry->Cookie) ||
        !$this->_registry->Cookie instanceof CookieComponent
        ) {
            throw new \RuntimeException('CookieComponent is not loaded');
        }

        $cookieConfig = $this->_config['cookie'];
        $cookieName = $this->_config['cookie']['name'];
        unset($cookieConfig['name']);
        $this->_registry->Cookie->configKey($cookieName, $cookieConfig);

        $data = $this->_registry->Cookie->read($cookieName);
        if (empty($data)) {
            return false;
        }

        extract($this->_config['fields']);
        if (empty($data[$username]) || empty($data[$password])) {
            return false;
        }

        $user = $this->_findUserWithExpiration($data[$username], $data[$password]);
        if ($user) {
            $request->session()->write(
                $this->_registry->Auth->sessionKey,
                $user
            );
            return $user;
        }

        return false;
    }

    /**
     * This is identical to BaseAuthenticate::_findUser() but it also
     * checks the timestamp of an optional tokenCreated field if supplied
     * in the configuration.  This is a fallback in case a cookie is
     * spoofed.
     *
     * @param string $username The username/identifier.
     * @prarm string|null The password/token.  If not provided, password
     * chceking is skipped.
     */
    public function _findUserWithExpiration($username, $password = null)
    {
        $userModel = $this->_config['userModel'];
        list(, $model) = pluginSplit($userModel);
        $fields = $this->_config['fields'];

        $conditions = [$model . '.' . $fields['username'] => $username];

        $scope = $this->_config['scope'];
        if ($scope) {
            $conditions = array_merge($conditions, $scope);
        }

        $table = TableRegistry::get($userModel)->find('all');

        $contain = $this->_config['contain'];
        if ($contain) {
            $table = $table->contain($contain);
        }

        $result = $table
            ->where($conditions)
            ->first();

        if (empty($result)) {
            return false;
        }
        
        $tokenCreatedField = $this->config('tokenCreated');
        if ($tokenCreatedField) {
            $expiration = str_replace('+', '', $this->config('cookie.expires'));
            if (!$result[$tokenCreatedField]->wasWithinLast($expiration)) {
                return false;
            }
        }

        if ($password !== null) {
            $hasher = $this->passwordHasher();
            $hashedPassword = $result->get($fields['password']);
            if (!$hasher->check($password, $hashedPassword)) {
                return false;
            }

            $this->_needsPasswordRehash = $hasher->needsRehash($hashedPassword);
            $result->unsetProperty($fields['password']);
        }

        return $result->toArray();
    }

    /**
     * Authenticate user
     *
     * @param Request $request Request object.
     * @param Response $response Response object.
     * @return array|bool Array of user info on success, false on falure.
     */
    public function authenticate(Request $request, Response $response)
    {
        return $this->getUser($request);
    }

    /**
     * Called from AuthComponent::logout()
     *
     * @param \Cake\Event\Event The dispatched Auth.logout event.
     * @return void
     */
    public function logout(Event $Event, $user)
    {
        $this->_registry->Cookie->delete($this->_config['cookie']['name']);
    }
}
