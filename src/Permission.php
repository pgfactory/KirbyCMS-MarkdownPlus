<?php

namespace PgFactory\MarkdownPlus;

//use function PgFactory\PageFactory\isLocalhost;
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
    private static string|bool $anonAccess = false;

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

        $admission = false;
        $permissionQuery = strtolower($permissionQuery);

        // special case 'nobody' or 'noone' -> deny in any case:
        if ($permissionQuery === 'nobody' || $permissionQuery === 'noone') {
            return false;

        // special case 'anybody' or 'anyone' -> always grant access:
        } elseif ($permissionQuery === 'anybody' || $permissionQuery === 'anyone') {
            return true;
        }



        // handle special option 'localhost' -> take session var into account:
        session_start();
        $debugOverride = ($_SESSION['pfy.debug']??null) === false;
        session_abort();
        if (str_contains($permissionQuery, 'localhost')) {
            if (self::isLocalhost() && !$debugOverride && $allowOnLocalhost) {
                return true;
            }
        }

        $user = self::checkPageAccessCode();

        $role = $email = false;
        if (is_object($user)) {
            $credentials = $user->credentials();
            $name = strtolower($credentials['name']??'');
            $email = strtolower($credentials['email']??'');
            $role = strtolower($user->role()->name());
        } else {
            $name = $user;
        }
        $loggedIn = (bool)$user;
        if ($permissionQuery === 'notloggedin' || $permissionQuery === 'anon') {
            $admission = !$loggedIn;

        } elseif (str_contains($permissionQuery, 'loggedin')) {
            $admission = $loggedIn;

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
     *
     * @return bool
     * @throws \Exception
     */
    public static function checkPageAccessCode(): mixed
    {
        // check whether there is an access code in url-args:
        if ($_GET['a']??false) {
            $user = self::evaluateAccessCode();
            unset($_GET['a']); // delete, so accessCode is only evaluated once

        } else {
            // no accessCode, so check whether it has been evaluated before:
            $session = kirby()->session();
            if ($user1 = $session->get('pfy.accessCodeUser')) {
                $user = $user1;
            } else {
                $user = self::$anonAccess;
            }
        }
        return $user;
    } // checkPageAccessCode


    /**
     * @return mixed
     * @throws \Throwable
     */
    private static function evaluateAccessCode(): mixed
    {
        $loggedInUser = kirby()->user();
        $session = kirby()->session();

        // get defined access codes:
        $pageAccessCodes = page()->accesscodes()->value();
        if (!$pageAccessCodes) {
            $pageAccessCodes = page()->accesscode()->value();
            if (!$pageAccessCodes) {
                return $loggedInUser; // no accessCodes defined, fall back to possibly logged-in user
            }
        }
        $pageAccessCodes = Data::decode($pageAccessCodes, 'YAML');
        if (!is_array($pageAccessCodes)) {
            return $loggedInUser; // no valid accessCodes defined, fall back to possibly logged-in user
        }

        // check order, flip array if necessary:
        $key1 = array_keys($pageAccessCodes)[0];
        if (!\Kirby\Toolkit\V::email($key1)) {
            $pageAccessCodes = array_flip($pageAccessCodes);
        }

        // get page name for logging:
        $page = substr(page()->url(), strlen(site()->url()) + 1) ?: 'home';

        // check whether submitted code has been defined:
        $submittedAccessCode = get('a', null); // get accessCode
        $found = array_search($submittedAccessCode, $pageAccessCodes);
        if ($found !== false) {
            if ($email = \Kirby\Toolkit\V::email($found) ? $found : false) {
                self::impersonateUser($email);
                $user = kirby()->user($email);
                if ($user) {
                    $session->set('pfy.accessCodeUser', $user);
                    self::mylog("AccessCode '$submittedAccessCode' validated and user logged-in as '$email' on page '$page/'", 'login-log.txt');
                } else {
                    $user = 'anon'; // actually, this case should not be possible
                }
            } else {
                // case accessCode without email -> grant as 'anon', but don't remember:
                $user = self::$anonAccess = 'anon';
                $session->remove('pfy.accessCodeUser');
                self::mylog("AccessCode '$submittedAccessCode' validated on page '$page/'", 'login-log.txt');
            }
            // grant access:
            return $user;

        } else {
            // no matching accessCode found, fall back to possibly logged-in user:
            self::mylog("AccessCode '$submittedAccessCode' rejected on page '$page/'", 'login-log.txt');
            return $loggedInUser;
        }
    } // evaluateAccessCode


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

