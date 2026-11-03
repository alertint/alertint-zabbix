<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\TestProblem;

/**
 * Where the test problem is on its way from Zabbix to an AlertINT Situation.
 * Read-only, polled by the Settings page.
 */
class TestStatus extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ok = $this->validateInput([
			'triggerid' => 'required|id',
			'since' => 'required|int32',
			'eventid' => 'string',
			'resolving' => 'in 0,1'
		]);

		$ok = $ok && preg_match('/^[0-9]*$/', $this->getInput('eventid', '')) === 1;

		if (!$ok) {
			$this->respond(['ok' => false, 'error' => _('Invalid request.')]);
		}

		return $ok;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$eventid = $this->getInput('eventid', '');
			$this->respond(['ok' => true] + TestProblem::progress(
				$this->getInput('triggerid'),
				(int) $this->getInput('since'),
				$eventid !== '' ? $eventid : null,
				$this->getInput('resolving', '0') === '1'
			));
		}
		catch (Exception $e) {
			$this->respond(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
