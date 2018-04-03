<?php
/**
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace EzSystems\EzPlatformXmlTextFieldTypeBundle\Command;

use DOMDocument;
use DOMXPath;
use eZ\Publish\Core\FieldType\RichText\Converter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use eZ\Publish\Core\FieldType\RichText\Converter\Aggregate;
use eZ\Publish\Core\FieldType\XmlText\Converter\Expanding;
use eZ\Publish\Core\FieldType\RichText\Converter\Ezxml\ToRichTextPreNormalize;
use eZ\Publish\Core\FieldType\XmlText\Converter\EmbedLinking;
use eZ\Publish\Core\FieldType\RichText\Converter\Xslt;
use eZ\Publish\Core\FieldType\RichText\Validator;
use eZ\Publish\Core\FieldType\XmlText\Value;

class ConvertXmlTextToRichTextCommand extends ContainerAwareCommand
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $connection;
    
    /**
     * @var \eZ\Publish\Core\FieldType\RichText\Converter
     */
    private $converter;
    
    /**
     * @var \eZ\Publish\Core\FieldType\RichText\Validator
     */
    private $validator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    public function __construct(Connection $connection, LoggerInterface $logger = null)
    {
        parent::__construct();

        $this->connection = $connection;
        $this->logger = $logger;

        $this->converter = new Aggregate(
            array(
                new ToRichTextPreNormalize(new Expanding(), new EmbedLinking()),
                new Xslt(
                    './vendor/ezsystems/ezpublish-kernel/eZ/Publish/Core/FieldType/RichText/Resources/stylesheets/ezxml/docbook/docbook.xsl',
                    array(
                        array(
                            'path' => './vendor/ezsystems/ezpublish-kernel/eZ/Publish/Core/FieldType/RichText/Resources/stylesheets/ezxml/docbook/core.xsl',
                            'priority' => 99,
                        ),
                    )
                ),
            )
        );

        $this->validator = new Validator(
            array(
                './vendor/ezsystems/ezpublish-kernel/eZ/Publish/Core/FieldType/RichText/Resources/schemas/docbook/ezpublish.rng',
                './vendor/ezsystems/ezpublish-kernel/eZ/Publish/Core/FieldType/RichText/Resources/schemas/docbook/docbook.iso.sch.xsl',
            )
        );
    }

    protected function configure()
    {
        $this
            ->setName('ezxmltext:convert-to-richtext')
            ->setDescription('Converts eZ Publish "legacy" XMLText fields to eZ Platform RichText fields')
            ->addArgument('content_types', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Optional argument specify content type(s) (by identifier) to convert, separated by space. If not set converts all')
            ->addOption('dry-run', InputOption::VALUE_NONE, 'Run the command in dry-run mode where not changes are done to the underlying data, but output of changes/errors are logged')
            ->setHelp( <<< EOT
The command <info>%command.name%</info> converts fields from XMLText to RichText




== WARNING ==

This is a non-finalized work in progress. ALWAYS make sure you have a restorable backup of your database before using it.
EOT
);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $contentTypeIdentifiers = (array) $input->getArgument('content_types');

        if ($contentTypeIdentifiers) {
            $query = $this->connection->createQueryBuilder();
            $query
                ->select('id')
                ->from('ezcontentobject')
                ->where('identifier = :identifier_list')
                ->setParameter(':identifier_list', $contentTypeIdentifiers, Connection::PARAM_STR_ARRAY);

            $contentTypes = $query->execute()->fetchAll(\PDO::FETCH_COLUMN, 0);
            var_dump($contentTypes);
        } else {
            $contentTypes = [];
        }

        $this->convertFieldDefinitions($output, $contentTypes, $dryRun);

        $this->convertFields($output, $contentTypes, $dryRun);
    }

    protected function convertFieldDefinitions(OutputInterface $output, array $contentTypes, $dryRun = false)
    {
        $query = $this->connection->createQueryBuilder();
        $query
            ->select('COUNT(a.id)')
            ->from('ezcontentclass_attribute', 'a')
            ->where('a.data_type_string = ezxmltext');

        if (!empty($contentTypes)) {
            $this->addWhereForContentType($query, $contentTypes);
        }

        $count = $query->execute()->fetchColumn();
        $output->writeln("Found $count field definitions to convert.");

        if ($dryRun) {
            return;
        }

        $updateQuery = $this->connection->createQueryBuilder();
        $updateQuery
            ->update('ezcontentclass_attribute', 'a')
            ->set('a.data_type_string', 'ezrichtext')
            ->set('a.data_text2', null)
            ->where('a.data_type_string = ezxmltext');

        if (!empty($contentTypes)) {
            $this->addWhereForContentType($updateQuery, $contentTypes);
        }

        $affectedRowsCount = $updateQuery->execute();
        $output->writeln("Converted $affectedRowsCount ezxmltext field definitions to ezrichtext");
    }

    protected function convertFields(OutputInterface $output, array $contentTypes, $dryRun = false)
    {
        $query = $this->connection->createQueryBuilder();
        $query
            ->select('COUNT(a.id)')
            ->from('ezcontentobject_attribute', 'a')
            ->where('a.data_type_string = ezxmltext');

        if (!empty($contentTypes)) {
            $this->addWhereForContentType($query, $contentTypes);
        }

        $count = $query->execute()->fetchColumn();
        $output->writeln("Found $count field rows to convert.");


        $query = $this->connection->createQueryBuilder();
        $query
            ->select('a.*')
            ->from('ezcontentobject_attribute', 'a')
            ->where('a.data_type_string = ezxmltext');

        if (!empty($contentTypes)) {
            $this->addWhereForContentType($query, $contentTypes);
        }

        $i = 0;
        $statement = $query->execute();
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['data_text'])) {
                $inputValue = Value::EMPTY_VALUE;
            } else {
                $inputValue = $row['data_text'];
            }

            // TODO: CATCH all exceptions
            $converted = $this->convert($inputValue);

            $updateQuery = $this->connection->createQueryBuilder();
            $updateQuery
                ->update('ezcontentobject_attribute', 'a')
                ->set('a.data_type_string', 'ezrichtext')
                ->set('a.data_text', $converted)
                ->where('a.id = :id')
                ->andWhere('a.version = :version')
                ->setParameter(':id', $row['id'], PDO::PARAM_INT)
                ->setParameter(':version', $row['version'], PDO::PARAM_INT);


            $updateQuery->execute();
            $this->logger->info(
                "Converted ezxmltext field #{$row['id']} to richtext",
                [
                    'original' => $inputValue,
                    'converted' => $converted
                ]
            );
            $i++;
        }


        $output->writeln("Converted $i ezxmltext fields to richtext");
    }

    private function addWhereForContentType(QueryBuilder $query, array $contentTypes)
    {
        $query
            ->andWhere('a.contentclass_id = :type_id_list')
            ->setParameter(':type_id_list', $contentTypes, Connection::PARAM_INT_ARRAY);
    }

    private function createDocument($xmlString)
    {
        $document = new DOMDocument();

        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        // TODO: AVOID ID ISSUES HERE? Or is that result of trasnformation? (add tests..)

        $document->loadXml($xmlString);

        return $document;
    }

    private function removeComments(DOMDocument $document)
    {
        $xpath = new DOMXpath($document);
        $nodes = $xpath->query('//comment()');

        for ($i = 0; $i < $nodes->length; ++$i) {
            $nodes->item($i)->parentNode->removeChild($nodes->item($i));
        }
    }

    private function convert($xmlString)
    {
        $inputDocument = $this->createDocument($xmlString);

        $this->removeComments($inputDocument);

        $convertedDocument = $this->converter->convert($inputDocument);

        // Needed by some disabled output escaping (eg. legacy ezxml paragraph <line/> elements)
        $convertedDocumentNormalized = new DOMDocument();
        $convertedDocumentNormalized->loadXML($convertedDocument->saveXML());

        $errors = $this->validator->validate($convertedDocument);

        $result = $convertedDocumentNormalized->saveXML();

        if (!empty($errors)) {
            $this->logger->error(
                "Validation errors when converting xmlstring",
                ['result' => $result, 'errors' => $errors, 'xmlString' => $xmlString]
            );
        }

        return $result;
    }
}
