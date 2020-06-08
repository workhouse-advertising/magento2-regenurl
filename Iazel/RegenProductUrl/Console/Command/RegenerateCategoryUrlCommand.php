<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends Command
{
    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;
    /**
     * @var Emulation
     */
    private $emulation;

    /**
     * RegenerateCategoryUrlCommand constructor.
     * @param State $state
     * @param Collection $collection
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param Emulation $emulation
     */
    public function __construct(
        State $state,
        Collection $collection,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        \Iazel\RegenProductUrl\Model\DbStorage $urlPersist,
        CategoryCollectionFactory $categoryCollectionFactory,
        Emulation $emulation
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->categoryCollectionFactory = $categoryCollectionFactory;

        parent::__construct();
        $this->emulation = $emulation;
    }

    protected function configure()
    {
        $this->setName('regenerate:category:url')
            ->setDescription('Regenerate url for given categories')
            ->addArgument(
                'cids',
                InputArgument::IS_ARRAY,
                'Categories to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            )
        ;
        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        try{
            $this->state->getAreaCode();
        }catch ( \Magento\Framework\Exception\LocalizedException $e){
            $this->state->setAreaCode('adminhtml');
        }

        $store_id = $inp->getOption('store');
        $this->emulation->startEnvironmentEmulation($store_id, Area::AREA_FRONTEND, true);

        $categories = $this->categoryCollectionFactory->create()
            ->setStore($store_id)
            ->addAttributeToSelect(['name', 'url_path', 'url_key']);

        $cids = $inp->getArgument('cids');
        if( !empty($cids) ) {
            $categories->addAttributeToFilter('entity_id', ['in' => $cids]);
        }

        $regenerated = 0;
        $currentLine = 0;
        $totalCount = $categories->count();
        echo "Regenerating URLs for {$totalCount} categories..." . PHP_EOL;
        foreach($categories as $category)
        {
            $currentLine++;
            $out->writeln("({$currentLine}/{$totalCount}) Regenerating urls for {$category->getName()} ({$category->getId()}). {$regenerated} URLs regenerated so far.");

            // TODO: The delete and insert needs to be wrapped in a transaction.
            // $this->urlPersist->deleteByData([
            //     UrlRewrite::ENTITY_ID => $category->getId(),
            //     UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
            //     UrlRewrite::REDIRECT_TYPE => 0,
            //     UrlRewrite::STORE_ID => $store_id
            // ]);

            $newUrls = $this->categoryUrlRewriteGenerator->generate($category);
            // Make sure that the request paths are valid as Magento may generate ones that are empty. This will cause the home page to be rewritten incorrectly.
            foreach ($newUrls as $urlKey => $newUrl) {
                if (!trim($newUrl->getRequestPath())) {
                    $out->writeln("<error>URL with the key '{$urlKey}' has an invalid request path of '{$newUrl->getRequestPath()}' so this rewrite is being skipped.</error>");
                    unset($newUrls[$urlKey]);
                }
            }
            // NOTE: The CategoryUrlRewriteGenerator doesn't check if URLs will conflict and `$this->urlPersist->replace(...)` doesn't actually
            //       do a `REPLACE` but does an `INSERT` instead. The URL conflicts cause batch inserts to fail, which in turn means that _all_ of the
            //       rewrites for the current category will have been deleted.
            // TODO: Report a bug regarding issues with CategoryUrlRewriteGenerator and propose better solutions to handling URL rewrites.
            try {
                // NOTE: Replaced the old $this->urlPersist with a better implementation.
                $this->urlPersist->replace($newUrls);
                $regenerated += count($newUrls);
            }
            catch(\Magento\Framework\Exception\AlreadyExistsException $e) {
                $out->writeln(sprintf('<error>Duplicated url for store ID %d, category %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $category->getId(), $category->getName(), $e->getMessage(), implode(PHP_EOL, array_keys($newUrls))));
            }
            // foreach ($newUrls as $newUrlKey => $newUrl) {
            //     $urlBatch = [
            //         $newUrlKey => $newUrl,
            //     ];
            //     try {
            //         $this->urlPersist->replace($urlBatch);
            //         $regenerated += count($urlBatch);
            //     }
            //     catch(\Exception $e) {
            //         $out->writeln(sprintf('<error>Duplicated url for store ID %d, category %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $category->getId(), $category->getName(), $e->getMessage(), implode(PHP_EOL, array_keys($urlBatch))));
            //     }
            // }
        }
        $this->emulation->stopEnvironmentEmulation();
        $out->writeln('Done regenerating. Regenerated ' . $regenerated . ' urls');
    }
}
