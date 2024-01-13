<?php

namespace PgFactory\MarkdownPlus;

use Kirby\Data\Data;
use PgFactory\PageFactory\PageFactory;
use function PgFactory\PageFactory\explodeTrim;

const MDP_LOG_PATH = 'site/logs/';


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

    /**
     * Evaluates a $permissionQuery against the current visitor's status.
     * @param string $permissionQuery
     * @return bool
     */
    public static function evaluate(string $permissionQuery, bool $allowOnLocalhost = true): bool
    {
        if (!$permissionQuery) {
            return false;
        }

        $permissionQueryStr = str_replace(' ', '', strtolower($permissionQuery));

        // handle special option 'localhost' -> take session var into account:
        session_start();
        if (($_SESSION['pfy.debug']??null) === false) { // debug explicitly false
            $allowOnLocalhost = false;
        }
        session_abort();
        if (str_contains($permissionQuery, 'localhost')) {
            if (self::isLocalhost() && $allowOnLocalhost) {
                return true;
            }
        }

        $user = self::checkPageAccessCode();

        $name = $role = $email = false;
        if (is_object($user)) {
            $name = strtolower($credentials['name']??'');
            $email = strtolower($credentials['email']??'');
            $role = strtolower($user->role()->name());
        }
        $loggedIn = (bool)$user;
        $admission = false;

        $queries = explodeTrim('|,', $permissionQueryStr);
        foreach ($queries as $permissionQuery) {
            // special case 'nobody' or 'noone' -> deny in any case:
            if ($permissionQuery === 'nobody' || $permissionQuery === 'noone') {
                return false;

                // special case 'anybody' or 'anyone' -> always grant access:
            } elseif ($permissionQuery === 'anybody' || $permissionQuery === 'anyone') {
                return true;
            }

            if ($permissionQuery === 'notloggedin' || $permissionQuery === 'anon,') {
                $admission |= !$loggedIn;

            } elseif ($permissionQuery === 'loggedin') {
                $admission |= $loggedIn;

            } elseif (preg_match('/^user=(\w+)/', $permissionQuery, $m)) {
                if (($name === $m[1]) || ($m[1] === 'loggedin')) { // explicit user
                    $admission |= $loggedIn;
                } elseif ($m[1] === 'anon') { // special case: user 'anon'
                    $admission |= !$loggedIn;
                }

            } elseif (preg_match('/^role=(\w+)/', $permissionQuery, $m)) {
                if ($role === $m[1]) { // explicit role
                    $admission |= $loggedIn;
                }

            } elseif (($name === $permissionQuery) || ($email === $permissionQuery) || ($role === $permissionQuery)) { // implicit
                $admission |= $loggedIn;
            }
        }
        return (bool)$admission;
    } // evaluate


    /**
     * AccessCodes are submitted as ?a=ABCDEFGH.
     * Valid AccessCodes are defined:
     *    - in user's profile as field 'AccessCode'
     *    - page's meta-files (aka .txt) as field 'AccessCode' -> anonymous access(!)
     * @return bool
     * @throws \Exception
     */
    public static function checkPageAccessCode(): mixed
    {
        $session = kirby()->session();
        $page = page()->id();

        // check whether there is an access code in url-args:
        if (!isset($_GET['a'])) {
            // check whether already granted:
            if ($email = $session->get('pfy.accessCodeUser')) {
                $user = kirby()->user($email);
                return $user;
            }

            if (self::$anonAccess[$page]??false) {
                return 'anon';
            }
            return kirby()->user(); // no access request, return regular login status

        } else {
            // get access code:
            $submittedAccessCode = get('a', null);
            unset($_GET['a']);
        }

        // first check against AccessCode of users:
        foreach (kirby()->users() as $user) {
            $name = $user->nameOrEmail()->value();
            $accessCode = $user->accesscode()->value();
            if ($submittedAccessCode === $accessCode) {
                // match found -> log in
                $email = $user->email();
                self::impersonateUser($email);
                $session->set('pfy.message', 'You are logged in now as '.$name);
                $session->set('pfy.accessCodeUser', $email);
                self::mylog("AccessCode '$submittedAccessCode' validated and user logged-in as '$email' on page '$page'", 'login-log.txt');
                return $user;
            }
        }

        // try to get "accessCode:" resp. "accessCodes:" from page (i.e. meta-file):
        $pageAccessCodes = page()->accesscodes()->value() ?: page()->accesscode()->value();
        $pageAccessCodes = Data::decode($pageAccessCodes, 'YAML');

        // check whether given code has been defined:
        if (is_array($pageAccessCodes) && in_array($submittedAccessCode, $pageAccessCodes)) {
            self::$anonAccess[$page] = true;
            self::mylog("AccessCode '$submittedAccessCode' validated on page '$page'", 'login-log.txt');
            return 'anon';
        } elseif (PageFactory::$debug??false) {
                self::mylog("Invalid AccessCode '$submittedAccessCode' received for page '$page'", 'login-log.txt');
        }
        return false;
    } // checkPageAccessCode


    /**
     * Given an email address of a registered user, that user is logged in by kirby()->impersonate($email)
     * @param string $userQuery
     * @return string|bool
     * @throws \Throwable
     */
    private static function impersonateUser(string $userQuery): string|bool
    {
        $email = Permission::findUsersEmail(strtolower($userQuery));
        if ($email) {
            if ($user = kirby()->impersonate($email)) {
                $email = strtolower($user->credentials()['email'] ?? '');
                return $email;
            } else {
                return true;
            }
        } else {
            return true;
        }
    } // impersonateUser


    /**
     * Given an email or username, checks existence resp. finds user's email address
     * @param string $searchKey
     * @return string|bool      fals or email address
     */
    public static function findUsersEmail(string $searchKey): string|bool
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
     * Returns true if running inside the same subnet (using netmask 255.255.255.0).
     *  (so, this could be a security risk if local subnet is not considered secure)
     * @return bool
     */
    public static function isLocalhost(): bool
    {
        // url-arg ?localhost=false let's you mimick a remote host:
        if (($_GET['localhost']??'') === 'false') {
            return false;
        }
        return self::ipInRange(kirby()->visitor()->ip(), $_SERVER['SERVER_ADDR']);
    } // isLocalhost


    /**
     * Check if a given ip is in a network
     * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
     * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
     * @return boolean true if the ip is in this range / false if not.
     */
    private static function ipInRange($ip, $range, $netmask = 24) {
        $range_decimal = ip2long( $range );
        $ip_decimal = ip2long( $ip );
        $wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
        $netmask_decimal = ~ $wildcard_decimal;
        return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
    } // ipInRange


    /**
     * @param string $str
     * @param mixed $filename
     * @return void
     * @throws \Exception
     */
    private static function mylog(string $str, mixed $filename = false): void
    {
        $filename = $filename?: 'log.txt';

        if (!\Kirby\Toolkit\V::filename($filename)) {
            return;
        }
        if (!file_exists(MDP_LOG_PATH)) {
            mkdir(MDP_LOG_PATH, recursive: true);
        }
        $logFile = MDP_LOG_PATH. $filename;

        $str = date('Y-m-d H:i:s')."  $str\n\n";
        if (file_put_contents($logFile, $str, FILE_APPEND) === false) {
            throw new \Exception("Writing to file '$logFile' failed");
        }
    } // mylog

} // Permission

