<?php declare(strict_types=1);
namespace T3\FluidPageCache\Reports;

/*  | This extension is made with ❤ for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2019-2020 Armin Vieweg <armin@v.ieweg.de>
 */
use T3\FluidPageCache\PageCacheManager;
use TYPO3\CMS\Backend\Module\AbstractFunctionModule;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Provides entry for Info module
 *
 * @TODO The AbstractFunctionModule this class is extending from will be removed in TYPO3 v10
 */
class PageCacheReport extends AbstractFunctionModule
{
    /**
     * @var ConnectionPool
     */
    protected $connectionPool;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Main method of modfuncreport
     *
     * @return string Module content
     * @throws \Exception
     */
    public function main()
    {
        /** @var StandaloneView $view */
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->getTemplatePaths()->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:fluid_page_cache/Resources/Private/Templates/PageCacheReport.html')
        );

        $cacheExistsInDatabase = false;
        foreach ($this->connectionPool->getConnectionNames() as $connectionName) {
            $connection = $this->connectionPool->getConnectionByName($connectionName);
            if (in_array('cf_cache_pages', $connection->getSchemaManager()->listTableNames(), true) &&
                in_array('cf_cache_pages_tags', $connection->getSchemaManager()->listTableNames(), true)
            ) {
                $cacheExistsInDatabase = true;
                break;
            }
        }

        $view->assign('now', new \DateTime());
        $id = (int) (GeneralUtility::_GET('id') ?? 0);
        $view->assign('id', $id);
        $view->assign('pageRow', BackendUtility::getRecord('pages', $id));
        $view->assign('cacheExistsInDatabase', $cacheExistsInDatabase);

        if ($id && $cacheExistsInDatabase) {
            $view->assign('identifiers', $this->getCacheIdentifiersByPageUid($id));
        }
        return $view->render();
    }

    protected function getCacheIdentifiersByPageUid(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('cf_cache_pages_tags');
        $cacheTagRows = $queryBuilder
            ->select('*')
            ->from('cf_cache_pages_tags')
            ->where('tag = "pageId_' . $pageUid . '"')
            ->execute()
            ->fetchAll(\PDO::FETCH_ASSOC) ?? [];

        $identifiers = [];
        foreach ($cacheTagRows as $cacheTagRow) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('cf_cache_pages');
            $cacheRow = $queryBuilder
                ->select('*')
                ->from('cf_cache_pages')
                ->where('identifier = "' . $cacheTagRow['identifier'] . '"')
                ->execute()
                ->fetch(\PDO::FETCH_ASSOC);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('cf_cache_pages_tags');
            $tagRows = $queryBuilder
                ->select('*')
                ->from('cf_cache_pages_tags')
                ->where('identifier = "' . $cacheTagRow['identifier'] . '"')
                ->execute()
                ->fetchAll(\PDO::FETCH_ASSOC);

            $tags = [];
            foreach ($tagRows as $tagRow) {
                $table = $uid = null;
                $tag = $tagRow['tag'];
                if (preg_match('/^' . PageCacheManager::CACHE_TAG_PREFIX . '(.*)_(\d*)$/i', $tag, $matches)) {
                    $table = $matches[1];
                    $uid = (int) $matches[2];
                } elseif (strpos($tag, 'pageId_') === 0) {
                    $table = 'pages';
                    $uid = (int) substr($tag, strlen('pageId_'));
                }
                $tags[] = [
                    'tag' => $tag,
                    'table' => $table,
                    'uid' => $uid,
                    'row' => ($table && $uid) ? BackendUtility::getRecord($table, $uid) : null,
                    'title' => ($table && $uid) ? $this->resolveRecordTitle($table, $uid) : null
                ];
            }
            $identifiers[$cacheTagRow['identifier']] = ['tags' => $tags, 'expires' => $cacheRow['expires']];
        }
        return $identifiers;
    }

    protected function resolveRecordTitle(string $table, int $uid): string
    {
        $labelField = $GLOBALS['TCA'][$table]['ctrl']['label'];
        if (!$labelField) {
            return '';
        }
        $row = BackendUtility::getRecord($table, $uid);
        return (string) $row[$labelField];
    }
}
