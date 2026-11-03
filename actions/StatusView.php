<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use CWebUser;

class StatusView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$response = new CControllerResponseData([
			'is_super_admin' => CWebUser::getType() == USER_TYPE_SUPER_ADMIN
		]);
		$response->setTitle(_('AlertINT Status'));
		$this->setResponse($response);
	}
}
