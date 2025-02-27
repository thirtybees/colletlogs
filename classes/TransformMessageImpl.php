<?php
namespace CollectLogsModule;

use Configuration;
use Db;
use DbQuery;
use GuzzleHttp\Client;
use PrestaShopException;
use Thirtybees\Core\DependencyInjection\ServiceLocator;
use Thirtybees\Core\Error\ErrorUtils;
use Throwable;
use Tools;

class TransformMessageImpl implements TransformMessage
{
    const SYNC_INTERVAL = 8 * 60 * 60;

    /**
     * @var Settings
     */
    private Settings $settings;

    /**
     * @var array
     */
    protected $messageConvertRegexp = null;

    /**
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param string $message
     *
     * @return string
     * @throws PrestaShopException
     */
    public function transform(string $message): string
    {
        foreach ($this->getMessageConvertors() as $entry) {
            $search = $entry['search'];
            $replace = $entry['replace'];
            $message = preg_replace($search, $replace, $message);
        }
        return $message;
    }

    /**
     * @param bool $force
     *
     * @return void
     */
    public function synchronize(bool $force = false)
    {
        try {
            $now = time();
            $lastSync = $this->settings->getLastSync();
            $timeSinceLastSync = (int)$now - $lastSync;
            if (!$force && ($timeSinceLastSync < static::SYNC_INTERVAL)) {
                return;
            }
            $this->settings->updateLastSync($now);

            $guzzle = new Client([
                'base_uri'    => Configuration::getApiServer(),
                'verify'      => Configuration::getSslTrustStore(),
            ]);


            $body = $guzzle->get('collectlogs/convert_message.json', ['headers' => $this->getHeaders()])->getBody();

            $json = json_decode((string)$body, true);
            if (! is_array($json)) {
                throw new PrestaShopException("Failed to parse response: " . $body);
            }

            if (! isset($json['success'])) {
                throw new PrestaShopException("Invalid response payload: " . $body);
            }

            if (! $json['success']) {
                if (isset($json['error'])) {
                    throw new PrestaShopException($json['error']);
                } else {
                    throw new PrestaShopException("Failure response: " . $body);
                }
            }

            $conn = Db::getInstance();
            $existing = [];
            foreach ($this->getMessageConvertors() as $entry) {
                $remoteId = $entry['remoteId'];
                if ($remoteId) {
                    $existing[$remoteId] = $remoteId;
                }
            }

            $data = $json['data'];
            foreach ($data as $row) {
                $remoteId = (int)$row['id'];
                if (isset($existing[$remoteId])) {
                    unset($existing[$remoteId]);
                } else {
                    $conn->insert('collectlogs_convert_message', [
                        'id_remote' => $remoteId,
                        'search' => pSQL($row['search'], true),
                        'replace' => pSQL($row['replace'], true)
                    ]);
                }
            }

            if ($existing) {
                $ids = implode(',', $existing);
                $conn->delete('collectlogs_convert_message', 'id_remote IN ('.$ids.')');
            }
        } catch (Throwable $t) {
            $errorHandler = ServiceLocator::getInstance()->getServiceLocator()->getErrorHandler();
            $errorHandler->logFatalError(ErrorUtils::describeException($t));
        }
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    protected function getMessageConvertors()
    {
        if (is_null($this->messageConvertRegexp)) {
            $conn = Db::getInstance();
            $this->messageConvertRegexp = [];
            $rows = $conn->getArray((new DbQuery())
                ->from('collectlogs_convert_message')
                ->orderBy('id_collectlogs_convert_message')
            );
            foreach ($rows as $row) {
                $this->messageConvertRegexp[] = [
                    'id' => (int)$row['id_collectlogs_convert_message'],
                    'remoteId' => (int)($row['id_remote'] ?? 0),
                    'search' => $row['search'],
                    'replace' => $row['replace']
                ];
            }
        }
        return $this->messageConvertRegexp;
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    protected function getHeaders()
    {
        return [
            'X-SID' => static::getSID()
        ];
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     */
    protected static function getSID()
    {
        static $sid = null;
        if (is_null($sid)) {
            if (method_exists('Configuration', 'getServerTrackingId')) {
                $sid = Configuration::getServerTrackingId();
            } else {
                $sid = Configuration::getGlobalValue('TB_TRACKING_UID');
                if (!$sid) {
                    $sid = Tools::passwdGen(40);
                    Configuration::updateGlobalValue('TB_TRACKING_UID', $sid);
                }
            }
        }
        return $sid;
    }
}