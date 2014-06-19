<?php

namespace N98\Magento\Command\System\Setup;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use N98\Util\Console\Helper\Table\Renderer\RendererFactory;

class CompareVersionsCommand extends AbstractMagentoCommand
{

    protected function configure()
    {
        $this
            ->setName('sys:setup:compare-versions')
            ->addOption('ignore-data', null, InputOption::VALUE_NONE, 'Ignore data updates')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output Format. One of [' . implode(',', RendererFactory::getFormats()) . ']'
            )
            ->setDescription('Compare module version with core_resource table.');
        $help = <<<HELP
Compares module version with saved setup version in `core_resource` table and displays version mismatch.
HELP;
        $this->setHelp($help);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output);
        if ($this->initMagento()) {
            $modules            = \Mage::getConfig()->getNode('modules');
            $resourceModel      = $this->_getResourceSingleton('core/resource', 'Mage_Core_Model_Resource_Resource');
            $setups             = \Mage::getConfig()->getNode('global/resources')->children();
            $ignoreDataUpdate   = $input->getOption('ignore-data');

            $headers = array('Setup', 'Module', 'DB', 'Data', 'Status');
            if ($ignoreDataUpdate) {
                unset($headers[array_search('Data', $headers)]);
            }

            $errorCounter = 0;
            $table = array();
            foreach ($setups as $setupName => $setup) {
                $moduleName     = (string) $setup->setup->module;
                $moduleVersion  = (string) $modules->{$moduleName}->version;
                $dbVersion      = (string) $resourceModel->getDbVersion($setupName);
                if (!$ignoreDataUpdate) {
                    $dataVersion = (string) $resourceModel->getDataVersion($setupName);
                }
                $ok = $dbVersion == $moduleVersion;
                if ($ok && !$ignoreDataUpdate) {
                    $ok = $dataVersion == $moduleVersion;
                }
                if (!$ok) {
                    $errorCounter++;
                }

                $row = array(
                    'Setup'     => $setupName,
                    'Module'    => $moduleVersion,
                    'DB'        => $dbVersion,
                );

                if (!$ignoreDataUpdate) {
                    $row['Data-Version'] = $dataVersion;
                }
                $row['Status'] = $ok ? 'OK' : 'Error';
                $table[] = $row;
            }

            //if there is no output format
            //highlight the status
            //and show error'd rows at bottom
            if (!$input->getOption('format')) {

                usort($table, function($a, $b) {
                    return $a['Status'] !== 'OK';
                });

                array_walk($table, function (&$row) {
                    $status             = $row['Status'];
                    $availableStatus    = array('OK' => 'info', 'Error' => 'error');
                    $statusString       = sprintf(
                        '<%s>%s</%s>',
                        $availableStatus[$status],
                        $status,
                        $availableStatus[$status]
                    );
                    $row['Status'] = $statusString;
                });
            }

            $this->getHelper('table')
                ->setHeaders($headers)
                ->renderByFormat($output, $table, $input->getOption('format'));

            //if no output format specified - output summary line
            if (!$input->getOption('format')) {
                if ($errorCounter > 0) {
                    $this->writeSection(
                        $output,
                        sprintf(
                            '%s error%s %s found!',
                            $errorCounter,
                            $errorCounter === 1 ? '' : 's',
                            $errorCounter === 1 ? 'was' : 'were'
                        ),
                        'error'
                    );
                } else {
                    $this->writeSection($output, 'No setup problems were found.', 'info');
                }
            }
        }
    }
}
