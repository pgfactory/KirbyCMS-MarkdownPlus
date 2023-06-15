<?php

namespace Usility\MarkdownPlus;

use function Usility\PageFactory\isLocalhost;
use Kirby\Data\Data;

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
    /**
     * Evaluates a $permissionQuery against the current visitor's status.
     * @param string $permissionQuery
     * @return bool
     */
    public static function evaluate(string $permissionQuery, bool $allowOnLocalhost = true): bool
    {
        $admission = false;
        $permissionQuery = strtolower($permissionQuery);

        // special case 'nobody' or 'noone' -> deny in any case:
        if ($permissionQuery === 'nobody' || $permissionQuery === 'noone') {
            if (!$allowOnLocalhost) { // exception: if overridden e.g. from PageFactory::$debug
                return false;
            }

        // special case 'anybody' or 'anyone' -> always grant access:
        } elseif ($permissionQuery === 'anybody' || $permissionQuery === 'anyone') {
            return true;
        }



        // handle special option 'localhost' -> take session var into account:
        $debugOverride = (kirby()->session()->get('pfy.debug', null) === false);
        if (str_contains($permissionQuery, 'localhost')) {
            if (self::isLocalhost() && !$debugOverride && $allowOnLocalhost) {
                return true;
            }
        }

        self::checkPageAccessCode();

        $name = $role = $email = false;
        $user = kirby()->user();
        if ($user) {
            $credentials = $user->credentials();
            $name = strtolower($credentials['name']??'');
            $email = strtolower($credentials['email']??'');
            $role = strtolower($user->role()->name());
        }
        $loggedIn = (bool)$user??false;
        if (str_contains($permissionQuery, 'loggedin')) {
            $admission = $loggedIn;

        } elseif (str_contains(',notloggedin,anonymous,nobody', ",$permissionQuery")) {
            $admission = !$loggedIn;

        } elseif (preg_match('/^user=(\w+)/', $permissionQuery, $m)) {
            if (($name === $m[1]) || ($m[1] === 'loggedin')) { // explicit user
                $admission = $loggedIn;
            } elseif ($m[1] === 'anon') { // special case: user 'anon'
                $admission = !$loggedIn;
            }

        } elseif (preg_match('/^role=(\w+)/', $permissionQuery, $m)) {
            if ($role === $m[1]) { // explicit role
                $admission = $loggedIn;
            }

        } elseif (($name === $permissionQuery) || ($email === $permissionQuery) || ($role === $permissionQuery)) { // implicit
                $admission = $loggedIn;
        }
        return $admission;
    } // evaluate


    /**
     * @return bool
     */
    private static function isLocalhost(): bool
    {
        // url-arg ?localhost=false let's you mimick a remote host:
        if (($_GET['localhost']??'') === 'false') {
            return false;
        }
        $ip = kirby()->visitor()->ip();
        return ((str_starts_with($ip, '192.')) || ($ip === '::1'));
    } // isLocalhost


    /**
     * @return bool
     * @throws \Exception
     */
    private static function checkPageAccessCode(): bool
    {
        // get access codes from meta-file "accessCode:" field:
        $pageAccessCodes = page()->accesscodes()->value();
        if (!$pageAccessCodes) {
            return false; // no access codes defined -> access denied
        }

        $session = kirby()->session();
        $page = substr(page()->url(), strlen(site()->url()) + 1) ?: 'home';
        $accessCode = get('a', null);

        // ?a without arg means logout:
        if (isset($_GET['a']) && !$accessCode) {
            $session->remove("pfy.access.$page");

        // check whether access already granted before:
        } elseif ($email = $session->get("pfy.access.$page")) {
            if (is_string($email)) {
                self::impersonateUser($email);
            }
            return true;

        } elseif (!$accessCode) {
            return false; // no access given
        }

        // prepare defined access codes:
        $pageAccessCodes = Data::decode($pageAccessCodes, 'YAML');
        $accessCodes = array_keys($pageAccessCodes);

        // check whether given code has been defined:
        if (is_array($accessCodes)) {
            $found = array_search($accessCode, $accessCodes);
            if ($found !== false) {
                // grant access:
                $name = $pageAccessCodes[$accessCodes[$found]];
                $email = self::impersonateUser($name);
                // set session variable for the current page -> grant access to this user if ?a= is omitted:
                $session->set("pfy.access.$page", $email);
                self::mylog("AccessCode '$accessCode' validated -> $name admitted on page '$page/'", 'login-log.txt');
                return true;
            } else {
                // deny access, log unsucessfull access attempt:
                self::mylog("Unknown AccessCode '$accessCode' on page '$page/'", 'login-log.txt');
            }
        } else {
            throw new \Exception("Error in page access code '_access' (in page's meta file): $accessCode");
        }
        return false; // no access given
    } // checkPageAccessCode


    /**
     * @param string $userQuery
     * @return string|bool
     * @throws \Throwable
     */
    private static function impersonateUser(string $userQuery): string|bool
    {
        if (str_contains($userQuery, '@')) {
            $email = $userQuery;
        } else {
            $email = false;
            $user = self::findUser($userQuery);
            if (is_object($user)) {
                $email = strtolower($user->credentials()['email'] ?? '');
            }
        }
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
     * @param string $value
     * @return object|bool
     */
    private static function findUser(string $value): object|bool
    {
        $users = kirby()->users();
        if ($user = $users->findBy('name', $value)) {
            return $user;
        }
        if ($user = $users->findBy('email', $value)) {
            return $user;
        }
        return false;
    } // renderUserList


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

