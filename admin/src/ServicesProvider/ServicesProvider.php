<?php
/**
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @copyright Copyright (c) 2019 Alexander Weissman
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/LICENSE.md (MIT License)
 */

namespace UserFrosting\Sprinkle\Admin\ServicesProvider;

use Interop\Container\ContainerInterface;

/**
 * Registers services for the admin sprinkle.
 *
 * @author Alex Weissman (https://alexanderweissman.com)
 */
class ServicesProvider
{
    /**
     * Register UserFrosting's admin services.
     *
     * @param ContainerInterface $container A DI container implementing ArrayAccess and container-interop.
     */
    public function register(ContainerInterface $container)
    {
        /**
         * Extend the 'classMapper' service to register sprunje classes.
         *
         * Mappings added: 'activity_sprunje', 'group_sprunje', 'permission_sprunje', 'role_sprunje', 'user_sprunje'
         *
         * @return \UserFrosting\Sprinkle\Core\Util\ClassMapper
         */
        $container->extend('classMapper', function ($classMapper, $c) {
            $classMapper->setClassMapping('activity_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\ActivitySprunje');
            $classMapper->setClassMapping('group_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\GroupSprunje');
            $classMapper->setClassMapping('permission_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\PermissionSprunje');
            $classMapper->setClassMapping('permission_user_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\PermissionUserSprunje');
            $classMapper->setClassMapping('role_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\RoleSprunje');
            $classMapper->setClassMapping('user_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\UserSprunje');
            $classMapper->setClassMapping('user_permission_sprunje', 'UserFrosting\Sprinkle\Admin\Sprunje\UserPermissionSprunje');

            return $classMapper;
        });
    }
}
