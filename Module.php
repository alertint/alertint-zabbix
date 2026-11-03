<?php

namespace Modules\AlertINT;

use APP;
use CMenu;
use CMenuItem;
use CWebUser;
use Zabbix\Core\CModule;

/**
 * Adds a top-level "AlertINT" section to the Zabbix main menu.
 * Settings is for Super admins only (saving module config needs that role).
 */
class Module extends CModule {

	public function init(): void {
		$items = [
			(new CMenuItem(_('Situations')))->setAction('alertint.situations'),
			(new CMenuItem(_('Status')))->setAction('alertint.status')
		];

		if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			$items[] = (new CMenuItem(_('Settings')))->setAction('alertint.settings');
		}

		APP::Component()->get('menu.main')->insertAfter(_('Monitoring'),
			(new CMenuItem(_('AlertINT')))
				->setIcon(ZBX_ICON_EYE)
				->setSubMenu(new CMenu($items))
		);
	}
}
