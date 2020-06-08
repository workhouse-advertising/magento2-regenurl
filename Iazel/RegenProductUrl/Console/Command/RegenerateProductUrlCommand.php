<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Store\Model\StoreManager;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var ProductRepositoryInterface
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * RegenerateProductUrlCommand constructor.
     * @param State $state
     * @param Collection $collection
     * @param ProductUrlRewriteGenerator $productUrlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        State $state,
        Collection $collection,
        ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        // UrlPersistInterface $urlPersist,
        // TODO: Consider updating this so that we just override the UrlPersistInterface.
        //       Although that could have undesired side-effects.
        \Iazel\RegenProductUrl\Model\DbStorage $urlPersist,
        StoreManagerInterface $storeManager
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
        $this->storeManager = $storeManager;
    }

    protected function configure()
    {
        $this->setName('regenerate:product:url')
            ->setDescription('Regenerate url for given products')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Products to regenerate'
            )
            ->addOption(
                'skus', 'k',
                InputOption::VALUE_REQUIRED,
                'A comma separated list of SKUs to filter by'
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
        try {
            $this->state->getAreaCode();
        } catch ( \Magento\Framework\Exception\LocalizedException $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $store_id = $inp->getOption('store');
        $stores = $this->storeManager->getStores(false);

        if (!is_numeric($store_id)) {
            $store_id = $this->getStoreIdByCode($store_id, $stores);
            if ($store_id) {
                $this->collection->addStoreFilter($store_id)->setStoreId($store_id);
            }
        }

        $pids = $inp->getArgument('pids');
        if (!empty($pids)) {
            $this->collection->addIdFilter($pids);
        }

        $skus = $inp->getOption('skus');
        if ($skus) {
            $skus = explode(',', $skus);
            $this->collection->addFieldToFilter('sku', ['in' => $skus]);
        }

        $this->collection->setVisibility([Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]);

        $this->collection->addAttributeToSelect(['url_path', 'url_key']);
        $list = $this->collection->load();
        $regenerated = 0;
        $totalCount = $list->count();
        echo "Regenerating URLs for {$totalCount} products..." . PHP_EOL;
        $currentLine = 0;
        foreach ($list as $product) {
            $currentLine++;
            echo "({$currentLine}/{$totalCount}) Regenerating URLs for product with the SKU {$product->getSku()} (ID: {$product->getId()})." . PHP_EOL;
            $product->setStoreId($store_id);

            // TODO: Consider having an option to clear old URL rewrites, although this is of limited value as that's easily done 
            //       with a database query.
            // $this->urlPersist->deleteByData([
            //     UrlRewrite::ENTITY_ID => $product->getId(),
            //     UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            //     UrlRewrite::REDIRECT_TYPE => 0,
            //     UrlRewrite::STORE_ID => $store_id
            // ]);

            $newUrls = $this->productUrlRewriteGenerator->generate($product);
            // Make sure that the request paths are valid as Magento may generate ones that are empty. This will cause the home page to be rewritten incorrectly.
            foreach ($newUrls as $urlKey => $newUrl) {
                if (!trim($newUrl->getRequestPath())) {
                    $out->writeln("<error>URL with the key '{$urlKey}' has an invalid request path of '{$newUrl->getRequestPath()}' so this rewrite is being skipped.</error>");
                    unset($newUrls[$urlKey]);
                }
            }

            // A work-around to process each item in the batch individually.
            // NOTE: The ProductUrlRewriteGenerator doesn't check if URLs will conflict and `$this->urlPersist->replace(...)` doesn't actually
            //       do a `REPLACE` but does an `INSERT` instead. The URL conflicts cause batch inserts to fail, which in turn means that _all_ of the
            //       rewrites for the current category will have been deleted.
            // TODO: Report a bug regarding issues with ProductUrlRewriteGenerator and propose better solutions to handling URL rewrites.
            try {
                $this->urlPersist->replace($newUrls);
                $regenerated += count($newUrls);
            } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
                $out->writeln(sprintf('<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $product->getId(), $product->getSku(), $e->getMessage(), implode(PHP_EOL, array_keys($urlBatch))));
            }
            // foreach ($newUrls as $newUrlKey => $newUrl) {
            //     $urlBatch = [
            //         $newUrlKey => $newUrl,
            //     ];
            //     try {
            //         $this->urlPersist->replace($urlBatch);
            //         $regenerated += count($urlBatch);
            //     } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
            //         $out->writeln(sprintf('<error>Duplicated url for store ID %d, product %d (%s) - %s Generated URLs:' . PHP_EOL . '%s</error>' . PHP_EOL, $store_id, $product->getId(), $product->getSku(), $e->getMessage(), implode(PHP_EOL, array_keys($urlBatch))));
            //     }
            // }
        }
        $out->writeln('Done regenerating. Regenerated ' . $regenerated . ' URLs.');
    }

    private function getStoreIdByCode($store_id, $stores)
    {
        foreach ($stores as $store) {
            if ($store->getCode() == $store_id) {
                return $store->getId();
            }
        }

        return Store::DEFAULT_STORE_ID;
    }
}
