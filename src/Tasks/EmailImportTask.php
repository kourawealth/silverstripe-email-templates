<?php

namespace LeKoala\EmailTemplates\Tasks;

use Exception;
use DOMElement;
use DOMDocument;
use SilverStripe\ORM\DB;
use SilverStripe\i18n\i18n;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use TractorCow\Fluent\Model\Locale;
use SilverStripe\Core\Manifest\ModuleLoader;
use LeKoala\EmailTemplates\Helpers\FluentHelper;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use LeKoala\EmailTemplates\Helpers\SubsiteHelper;
use SilverStripe\Core\Config\Config;
use SilverStripe\i18n\TextCollection\i18nTextCollector;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Import email templates provided from ss files
 *
 * \vendor\silverstripe\framework\templates\SilverStripe\Control\Email
 * \vendor\silverstripe\framework\templates\SilverStripe\Control\Email\ForgotPasswordEmail.ss
 *
 * \app\templates\Email\MySampleEmail.ss
 *
 * Finds all *Email.ss templates and imports them into the CMS
 * @author lekoala
 */
class EmailImportTask extends BuildTask
{
    // Keeps the legacy dev/tasks/EmailImportTask route working
    protected static string $commandName = 'EmailImportTask';

    protected string $title = "Email import task";

    protected static string $description = "Finds all *Email.ss templates and imports them into the CMS, if they don't already exist.";

    public static function getHelp(): string
    {
        return 'Remember to flush the templates/translations if needed';
    }

    public function getOptions(): array
    {
        return [
            new InputOption('clear', null, InputOption::VALUE_NONE, 'Delete all existing email templates before running the task'),
            new InputOption('overwrite', null, InputOption::VALUE_REQUIRED, 'Overwrite templates that exist in the CMS (soft|hard). Soft will replace a template if not modified by the user, hard will replace it even if modified by the user'),
            new InputOption('templates', null, InputOption::VALUE_REQUIRED, 'Comma separated list of template codes to import'),
            new InputOption('subsite', null, InputOption::VALUE_REQUIRED, 'Requires subsites. Create email templates in all subsites including main site (all), or only in the chosen subsite (subsite ID). If a subsite is active, it will be used by default'),
            new InputOption('locales', null, InputOption::VALUE_REQUIRED, 'Requires fluent. Comma separated list of locales to import, e.g. fr,en'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $subsiteSupport = SubsiteHelper::usesSubsite();
        $fluentSupport = FluentHelper::usesFluent();

        $overwrite = $input->getOption('overwrite');
        $clear = $input->getOption('clear');
        $templatesToImport = $input->getOption('templates');
        $importToSubsite = $input->getOption('subsite');
        $chosenLocales = $input->getOption('locales');

        // Normalize argument
        if ($overwrite && $overwrite != 'soft' && $overwrite != 'hard') {
            $overwrite = 'soft';
        }

        // Select which subsite to import emails to
        $subsites = [];
        if ($subsiteSupport) {
            if ($importToSubsite == 'all') {
                $subsites = SubsiteHelper::listSubsites();
            } elseif (is_numeric($importToSubsite)) {
                $subsites = SubsiteHelper::listSubsites();
                $subsiteTitle = 'Subsite #' . $importToSubsite;
                foreach ($subsites as $subsite) {
                    if ($subsite->ID == $importToSubsite) {
                        $subsiteTitle = $subsite->Title;
                    }
                }
                $subsites = [
                    $importToSubsite => $subsiteTitle
                ];
            }
            if (SubsiteHelper::currentSubsiteID()) {
                $output->writeln("<info>Importing to current subsite. Run from main site to import other subsites at once.</>");
                $subsites = [];
            }
            if (!empty($subsites)) {
                $output->writeln("<info>Importing to subsites : " . implode(',', array_values($subsites)) . "</>");
            }
        }

        if ($templatesToImport) {
            $templatesToImport = explode(',', $templatesToImport);
        }

        // Do we clear our templates?
        if ($clear) {
            $output->writeln("<info>Clear all email templates</>");
            $emailTemplates = EmailTemplate::get();
            foreach ($emailTemplates as $emailTemplate) {
                $emailTemplate->delete();
            }
        }

        $emailTemplateSingl = singleton(EmailTemplate::class);

        $locales = null;
        if ($fluentSupport && class_exists('TractorCow\Fluent\Model\Locale')) {
            if (FluentHelper::isClassTranslated(EmailTemplate::class)) {
                $locales = Locale::get()->column('Locale');

                // We collect only one locale, restrict the list
                if ($chosenLocales) {
                    $arr = explode(',', $chosenLocales);
                    $locales = [];
                    foreach ($arr as $a) {
                        $a = FluentHelper::get_locale_from_lang($a);
                        $locales[] = $a;
                    }
                }
            }
        }

        $defaultLocale = FluentHelper::get_locale();

        $templates = $this->collectTemplates();

        // don't throw errors
        Config::modify()->set(i18n::class, 'missing_default_warning', false);

        foreach ($templates as $filePath) {
            $isOverwritten = false;

            $fileName = basename($filePath, '.ss');

            // Remove base path
            $relativeFilePath = str_replace(Director::baseFolder(), '', $filePath);
            $relativeFilePathParts = explode('/', trim($relativeFilePath, '/'));

            // Group by module
            $moduleName = array_shift($relativeFilePathParts);
            if ($moduleName == 'vendor') {
                $moduleVendor = array_shift($relativeFilePathParts);
                // get module name
                $moduleName = $moduleVendor . '/' . array_shift($relativeFilePathParts);
            }

            // remove /templates part
            array_shift($relativeFilePathParts);
            $templateName = str_replace('.ss', '', implode('/', $relativeFilePathParts));

            $templateTitle = basename($templateName);

            // Create the email code (basically, the template name without "Email" at the end)
            $code = preg_replace('/Email$/', '', $fileName);

            if (!empty($templatesToImport) && !in_array($code, $templatesToImport)) {
                $output->writeln("<comment>Template with code $code was ignored.</>");
                continue;
            }

            $whereCode = [
                'Code' => $code
            ];
            $emailTemplate = EmailTemplate::get()->filter($whereCode)->first();

            // Check if it has been modified or not
            $templateModified = false;
            if ($emailTemplate) {
                $templateModified = $emailTemplate->Created != $emailTemplate->LastEdited;
            }

            if (!$overwrite && $emailTemplate) {
                $output->writeln("<comment>Template with code $code already exists. Choose overwrite if you want to import again.</>");
                continue;
            }
            if ($overwrite == 'soft' && $templateModified) {
                $output->writeln("<comment>Template with code $code has been modified by the user. Choose overwrite=hard to change.</>");
                continue;
            }

            // Create a default title from code
            $title = preg_split('/(?=[A-Z])/', $code);
            $title = implode(' ', $title);

            // Get content of the email
            $content = file_get_contents($filePath);

            // Analyze content to find incompatibilities
            $errors = self::checkContentForErrors($content);
            if (!empty($errors)) {
                $output->writeln("<error>Invalid syntax was found in '$relativeFilePath'. Please fix these errors before importing the template</>");
                $output->writeListItem($errors);
                continue;
            }

            // Parse language
            $module = ModuleLoader::getModule($moduleName);
            $collector = new i18nTextCollector;
            $entities = $collector->collectFromTemplate($content, $fileName, $module);

            /*
            array:1 [▼
            "MyEmail.SUBJECT" => "My subject"
            ]
            */

            $translationTable = [];
            foreach ($entities as $entity => $data) {
                if ($locales) {
                    foreach ($locales as $locale) {
                        i18n::set_locale($locale);
                        if (!isset($translationTable[$entity])) {
                            $translationTable[$entity] = [];
                        }
                        $translationTable[$entity][$locale] = i18n::_t($entity, $data);
                    }
                    i18n::set_locale($defaultLocale);
                } else {
                    $translationTable[$entity] = [$defaultLocale => i18n::_t($entity, $data)];
                }
            }

            $contentLocale = [];
            // May be null
            if ($locales) {
                foreach ($locales as $locale) {
                    $contentLocale[$locale] = $content;
                }
            }
            if (!isset($contentLocale[$defaultLocale])) {
                $contentLocale[$defaultLocale] = $content;
            }

            // Now we use our translation table to manually replace _t calls into file content
            foreach ($translationTable as $entity => $translationData) {
                // use the double escape notation
                $escapedEntity = str_replace('\\', '\\\\\\\\', $entity);
                // fix dot notation
                $escapedEntity = str_replace('.', '\.', $escapedEntity);
                $baseTranslation = null;

                foreach ($translationData as $locale => $translation) {
                    if (!$baseTranslation && $translation) {
                        $baseTranslation = $translation;
                    }
                    if (!$translation) {
                        $translation = $baseTranslation;
                    }
                    // This regex should match old and new style
                    $count = 0;
                    $contentLocale[$locale] = preg_replace("/<%(t | _t\(')" . $escapedEntity . "( |').*?%>/ums", $translation, $contentLocale[$locale], -1, $count);
                    if (!$count) {
                        throw new Exception("Failed to replace $escapedEntity with translation $translation");
                    }
                }
            }

            // Create a template if necassery or mark as overwritten
            if (!$emailTemplate) {
                $emailTemplate = new EmailTemplate;
            } else {
                $isOverwritten = true;
            }

            // Other properties
            $emailTemplate->Code = $code;
            $emailTemplate->Category = $moduleName;
            if (SubsiteHelper::currentSubsiteID() && !$emailTemplate->SubsiteID) {
                $emailTemplate->SubsiteID = SubsiteHelper::currentSubsiteID();
            }
            // Write to main site or current subsite
            $emailTemplate->write();

            // Apply content to email after write to ensure we can localize properly
            $this->assignContent($emailTemplate, $contentLocale[$defaultLocale]);

            if (!empty($locales)) {
                foreach ($locales as $locale) {
                    $this->assignContent($emailTemplate, $contentLocale[$locale], $locale);
                }
            }

            // Reset date to allow tracking user edition (for soft/hard overwrite)
            $this->resetLastEditedDate($emailTemplate->ID);

            // Loop through subsites
            if (!empty($importToSubsite)) {
                SubsiteHelper::disableFilter();
                foreach ($subsites as $subsiteID => $subsiteTitle) {
                    $whereCode['SubsiteID'] = $subsiteID;

                    $subsiteEmailTemplate = EmailTemplate::get()->filter($whereCode)->first();

                    $emailTemplateCopy = $emailTemplate;
                    $emailTemplateCopy->SubsiteID = $subsiteID;
                    if ($subsiteEmailTemplate) {
                        $emailTemplateCopy->ID = $subsiteEmailTemplate->ID;
                    } else {
                        $emailTemplateCopy->ID = 0; // New
                    }
                    $emailTemplateCopy->write();

                    $this->resetLastEditedDate($emailTemplateCopy->ID);
                }
            }

            if ($isOverwritten) {
                $output->writeln("<info>Overwrote {$emailTemplate->Code}</>");
            } else {
                $output->writeln("<info>Imported {$emailTemplate->Code}</>");
            }
        }

        return Command::SUCCESS;
    }

    public static function checkContentForErrors($content)
    {
        $errors = [];
        if (strpos($content, '<% with') !== false) {
            $errors[] = 'Replace "with" blocks by plain calls to the variable';
        }
        if (strpos($content, '<% if') !== false) {
            $errors[] = 'If/else logic is not supported. Please create one template by use case or abstract logic into the model';
        }
        if (strpos($content, '<% loop') !== false) {
            $errors[] = 'Loops are not supported. Please create a helper method on the model to render the loop';
        }
        if (strpos($content, '<% sprintf') !== false) {
            $errors[] = 'You should not use sprintf to escape content, please use plain _t calls';
        }
        return $errors;
    }

    /**
     * Collect email from your project
     *
     * @return array
     */
    protected function collectTemplates()
    {
        $templates = glob(Director::baseFolder() . '/' . project() . '/templates/Email/*Email.ss');

        $framework = self::config()->get('import_framework');
        if ($framework) {
            // use ? to avoid matching plain Email.ss
            $templates = array_merge($templates, glob(Director::baseFolder() . '/vendor/silverstripe/framework/templates/SilverStripe/Control/Email/?*Email.ss'));
        }
        $extra = self::config()->get('extra_paths');
        foreach ($extra as $path) {
            $path = trim($path, '/');
            $templates = array_merge($templates, glob(Director::baseFolder() . '/' . $path . '/*Email.ss'));
        }

        return $templates;
    }

    /**
     * Utility function to reset email templates last edited date
     *
     * @param int $ID
     * @return void
     */
    protected function resetLastEditedDate($ID)
    {
        DB::query("UPDATE `EmailTemplate` SET LastEdited = Created WHERE ID = " . $ID);
    }

    /**
     * Update a template with content
     *
     * @param EmailTemplate $emailTemplate
     * @param string $content The full page content with html
     * @param string $locale
     * @return void
     */
    protected function assignContent(EmailTemplate $emailTemplate, $content, $locale = '')
    {
        FluentHelper::withLocale($locale, function () use ($emailTemplate, $content) {
            // First assign the whole string to Content in case it's not split by zones
            $cleanContent = $this->cleanContent($content);
            $emailTemplate->Content = '';
            $emailTemplate->Content = $cleanContent;

            $source = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
            $dom = new DomDocument('1.0', 'UTF-8');
            $dom->loadHTML(mb_encode_numericentity($source, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));

            // Look for nodes to assign to proper fields (will overwrite content)
            $fields = ['Content', 'Callout', 'Subject'];
            foreach ($fields as $field) {
                $node = $dom->getElementById($field);
                if ($node) {
                    $cleanContent = $this->cleanContent($this->getInnerHtml($node));
                    $emailTemplate->$field = '';
                    $emailTemplate->$field = $cleanContent;
                }
            }

            // No subject? try conventions
            if (!$emailTemplate->Subject) {
                switch ($emailTemplate->Code) {
                    case 'ChangePassword':
                        $entity = "SilverStripe\\Security\\Member.SUBJECTPASSWORDCHANGED";
                        break;
                    case 'ForgotPassword':
                        $entity = "SilverStripe\\Security\\Member.SUBJECTPASSWORDRESET";
                        break;
                    default:
                        $entity = $emailTemplate->Code . 'Email.SUBJECT';
                        break;
                }
                $subject = _t($entity);
                if ($subject) {
                    $emailTemplate->Subject = $subject;
                }
            }

            // Write each time within the given state
            $emailTemplate->write();
        });
    }

    /**
     * Get a clean string
     *
     * @param string $content
     * @return string
     */
    protected function cleanContent($content)
    {
        $content = strip_tags($content, '<p><br><br/><div><img><a><span><ul><li><strong><em><b><i><blockquote><h1><h2><h3><h4><h5><h6>');
        $content = str_replace("’", "'", $content);
        $content = trim($content);
        $content = nl2br($content);
        return $content;
    }

    /**
     * Loop over a node to extract all html
     *
     * @param DOMElement $node
     * @return string
     */
    protected function getInnerHtml(DOMElement $node)
    {
        $innerHTML = '';
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $child->ownerDocument->saveXML($child);
        }
        return $innerHTML;
    }
}
