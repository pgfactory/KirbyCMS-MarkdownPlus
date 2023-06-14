<?php

namespace Usility\MarkdownPlus;

use function Usility\PageFactory\isLocalhost;

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
        if ($permissionQuery === 'noone') {
            return false;
        }

        // handle special option '|localhost':
        if (str_contains($permissionQuery, 'localhost')) {
            if (isLocalhost() && $allowOnLocalhost) {
                return true;
            }
            $permissionQuery = str_replace('|localhost', '', $permissionQuery);
        }

        $permissionQuery = strtolower($permissionQuery);
        if ($permissionQuery === 'anybody' || $permissionQuery === 'anyone') {
            return true;
        }

        $name = $role = $email = false;
        $user = kirby()->user();
        if ($user) {
            $name = strtolower($user->credentials()['name']??'');
            $email = strtolower($user->credentials()['email']??'');
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
} // Permission

