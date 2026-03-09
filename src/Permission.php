<?php

namespace PgFactory\MarkdownPlus;

use Kirby\Data\Data;
use PgFactory\PageFactory\PageFactory;
use function PgFactory\PageFactory\explodeTrim;

const MDP_LOG_PATH = MDP_BASE_PATH . 'site/logs/';


/**
 * Evaluates a $permissionQuery against the current visitor's status.
 * Options:
 *  $permissionQuery = true         -> synonym for 'loggedin|loggedin'
 *  $permissionQuery = loggedin     -> permitted if a visitor is logged in, no matter what role
 *  $permissionQuery = anybody      -> always permitted, no matter whether logged in or not
 *  $permissionQuery = anon         -> permitted if NOT logged in
 *  $permissionQuery = 'user=xy'    -> permitted if username is 'xy'
 *  $permissionQuery = 'role=xy'    -> permitted if user's role is 'xy'
 *  $permissionQuery = 'xy'         -> permitted if role or username or user's email is 'xy'
 *  $permissionQuery = 'localhost'  -> permitted if browser running on local host
 *  $permissionQuery = 'xy|localhost'-> combined with other criteria
 */
class Permission
{
    private static array $anonAccess = [];
    private static bool|null $isLocalhost = null;

    /**
     * Evaluates a $permissionQuery against the current visitor's status.
     * @param string $permissionQuery
     * @param bool $allowOnLocalhost
     * @return bool
     */
    public static function evaluate(string $permissionQuery, bool $allowOnLocalhost = true): bool
    {
        if (!$permissionQuery) {
            return false;
        }

        $permissionQueryStr = str_replace(' ', '', strtolower($permissionQuery));

        if (str_contains($permissionQueryStr, 'localhost')) {
            if (self::isLocalhost() && $allowOnLocalhost) {
                return true;
            }
        }

        $user = self::checkPageAccessCode();

        $name = $role = $email = false;
        if (is_object($user)) {
            $name  = strtolower($user->name());
            $email = strtolower($user->email());
            $role  = strtolower($user->role()->name());
        }
        $loggedIn = (bool)$user;
        $admission = false;

        $queries = explodeTrim('|,', $permissionQueryStr);
        foreach ($queries as $query) {
            // special case 'nobody' or 'noone' -> deny in any case:
            if ($query === 'nobody' || $query === 'noone') {
                return false;
            }

            // special case 'anybody' or 'anyone' -> always grant access:
            if ($query === 'anybody' || $query === 'anyone') {
                return true;
            }

            if ($query === 'notloggedin' || $query === 'anon') {
                $admission = $admission || !$loggedIn;

            } elseif ($query === 'loggedin') {
                $admission = $admission || $loggedIn;

            } elseif (preg_match('/^user=(\w+)/', $query, $m)) {
                if (($name === $m[1]) || ($m[1] === 'loggedin')) {
                    $admission = $admission || $loggedIn;
                } elseif ($m[1] === 'anon') {
                    $admission = $admission || !$loggedIn;
                }

            } elseif (preg_match('/^role=(\w+)/', $query, $m)) {
                if ($role === $m[1]) {
                    $admission = $admission || $loggedIn;
                }

            } elseif ($name === $query || $email === $query || $role === $query) {
                $admission = $admission || $loggedIn;
            }
        }
        return $admission;
    } // evaluate


    /**
     * AccessCodes are submitted as ?a=ABCDEFGH.
     * Valid AccessCodes are defined:
     *    - in user's profile as field 'AccessCode'
     *    - page's meta-files (aka .txt) as field 'AccessCode' -> anonymous access(!)
     * @return mixed
     * @throws \Exception
     */
    public static function checkPageAccessCode(): mixed
    {
        $session = kirby()->session();
        $page = page() ? page()->id() : '';
        $accessCodeKey = kirby()->option('pgfactory.markdownplus.accessCodeKey', 'a');

        // check whether there is an access code in url-args:
        if (!isset($_GET[$accessCodeKey])) {
            // check whether already granted:
            if ($email = $session->get('pfy.accessCodeUser')) {
                return kirby()->user($email);
            }

            if (self::$anonAccess[$page] ?? false) {
                return 'anon';
            }
            return kirby()->user(); // no access request, return regular login status

        } else {
            // get access code:
            $submittedAccessCode = get($accessCodeKey, null);
            unset($_GET[$accessCodeKey]);
        }

        // first check against AccessCode of users:
        foreach (kirby()->users() as $user) {
            $role = strtolower($user->role()->name());
            if ($role === 'admin') {
                continue;
            }
            $name = $user->nameOrEmail()->value();
            $accessCode = $user->accesscode()->value();
            if ($submittedAccessCode === $accessCode) {
                // match found -> log in
                $email = $user->email();
                $message = 'You are logged in now as ' . $name;
                $session->set('pfy.accessCodeUser', $email);
                self::mylog("AccessCode '$submittedAccessCode' validated and user logged-in as '$email' on page '$page'", PFY_LOGIN_LOG_FILE);
                self::reloadAgent(message: $message);
            }
        }

        // try to get "accessCode:" resp. "accessCodes:" from page (i.e. meta-file):
        if (!page()) {
            return false;
        }
        $pageAccessCodes = page()->accesscodes()->value() ?: page()->accesscode()->value();
        $pageAccessCodes = Data::decode($pageAccessCodes, 'YAML');

        // check whether given code has been defined:
        if (is_array($pageAccessCodes) && in_array($submittedAccessCode, $pageAccessCodes)) {
            self::$anonAccess[$page] = true;
            self::mylog("AccessCode '$submittedAccessCode' validated on page '$page'", PFY_LOGIN_LOG_FILE);
            return 'anon';
        } elseif (PageFactory::$dev ?? false) {
            self::mylog("Invalid AccessCode '$submittedAccessCode' received for page '$page'", PFY_LOGIN_LOG_FILE);
        }
        return false;
    } // checkPageAccessCode


    /**
     * Given an email or username, checks existence resp. finds user's email address
     * @param string $searchKey
     * @return string|false
     */
    public static function findUsersEmail(string $searchKey): string|false
    {
        $searchKey = strtolower($searchKey);
        if (str_contains($searchKey, '@')) {
            foreach (kirby()->users() as $user) {
                if ($user->email() === $searchKey) {
                    return $searchKey;
                }
            }

        } else {
            foreach (kirby()->users() as $user) {
                $email = $user->email();
                if (($email === $searchKey) || (strtolower($user->name()->value()) === $searchKey)) {
                    return $email;
                }
            }
        }
        return false;
    } // findUsersEmail


    /**
     * Checks whether visitor is logged with role=admin
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = self::getLoggedInUser();
        if ($user) {
            return (string)$user->role() === 'admin';
        }
        return false;
    } // isAdmin


    /**
     * Returns true if running inside the same subnet (using netmask 255.255.255.0).
     *  (so, this could be a security risk if local subnet is not considered secure)
     * @return bool
     */
    public static function isLocalhost(): bool
    {
        if (self::$isLocalhost !== null) {
            // already evaluated -> return cached result:
            return self::$isLocalhost;
        }

        // evaluate whether running on localhost:
        $isLocalhost = kirby()->option('isLocalhost');
        if ($isLocalhost === null) {
            $ip = $_SERVER['SERVER_ADDR'];
            $isLocalhost = ($ip === '::1' || $ip === '127.0.0.1' || str_starts_with($ip, '192.168.'));
        }
        self::$isLocalhost = $isLocalhost;
        if (!$isLocalhost) {
            return false;
        }

        if (isset($_GET['localhost'])) {
            // there is a url-command ?localhost:
            $localhostRequest = $_GET['localhost'];
            if ($localhostRequest === 'false') { // allows to suppress localhost state
                kirby()->session()->set('pfy.notLocalhost', true);
                self::$isLocalhost = false;
                return false;
            } else {
                // in any other case, reset session var:
                kirby()->session()->remove('pfy.notLocalhost');
                self::reloadAgent();
                return true;
            }
        } else {
            // no request, check session var:
            $isLocalhost = !kirby()->session()->get('pfy.notLocalhost');
            self::$isLocalhost = $isLocalhost;
            return $isLocalhost;
        }
    } // isLocalhost


    /**
     * Checks login state, taking login via access code into account
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        if (kirby()->user() !== null) {
            return true;
        }
        $session = kirby()->session();
        if ($email = $session->get('pfy.accessCodeUser')) {
            return (bool)kirby()->user($email);
        }
        return false;
    } // isLoggedIn


    /**
     * @return \Kirby\Cms\User|false
     */
    public static function getLoggedInUser(): mixed
    {
        if (($user = kirby()->user()) !== null) {
            return $user;
        }
        $session = kirby()->session();
        if ($email = $session->get('pfy.accessCodeUser')) {
            return kirby()->user($email);
        }
        return false;
    } // getLoggedInUser


    /**
     * @param string $str
     * @param string $filename
     * @return void
     * @throws \Exception
     */
    private static function mylog(string $str, string $filename = 'log.txt'): void
    {
        if (!\Kirby\Toolkit\V::filename($filename)) {
            return;
        }

        if (!file_exists(MDP_LOG_PATH)) {
            mkdir(MDP_LOG_PATH, recursive: true);
        }
        $logFile = MDP_LOG_PATH . $filename;

        $str = date('Y-m-d H:i:s') . "  $str\n\n";
        if (file_put_contents($logFile, $str, FILE_APPEND) === false) {
            throw new \Exception("Writing to file '$logFile' failed");
        }
    } // mylog


    /**
     * Forces the browser to reload.
     * If a message is provided, it is stored in session and displayed on reload.
     * @param string $target
     * @param string $message
     * @return void
     */
    private static function reloadAgent(string $target = '', string $message = ''): void
    {
        if (!$target) {
            $target = page()->url();
        }
        if ($message) {
            kirby()->session()->set('pfy.message', $message);
        }
        header("Location: $target");
        exit;
    } // reloadAgent

} // Permission
