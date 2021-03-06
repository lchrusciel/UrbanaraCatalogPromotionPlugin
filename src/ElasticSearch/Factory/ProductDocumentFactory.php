<?php

declare(strict_types=1);

namespace Urbanara\CatalogPromotionPlugin\ElasticSearch\Factory;

use ONGR\ElasticsearchBundle\Collection\Collection;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\ElasticSearchPlugin\Document\PriceDocument;
use Sylius\ElasticSearchPlugin\Exception\UnsupportedFactoryMethodException;
use Sylius\ElasticSearchPlugin\Factory\ProductDocumentFactoryInterface;
use Sylius\ElasticSearchPlugin\Factory\ProductFactoryInterface;
use Urbanara\CatalogPromotionPlugin\Action\CatalogDiscountActionCommandInterface;
use Urbanara\CatalogPromotionPlugin\ElasticSearch\Document\AppliedPromotionDocument;
use Urbanara\CatalogPromotionPlugin\ElasticSearch\Document\DecorationDocument;
use Urbanara\CatalogPromotionPlugin\ElasticSearch\Document\ProductDocument;
use Urbanara\CatalogPromotionPlugin\Entity\CatalogPromotionDecoration;
use Urbanara\CatalogPromotionPlugin\Provider\CatalogPromotionProviderInterface;

final class ProductDocumentFactory implements ProductDocumentFactoryInterface
{
    /** @var ProductDocument */
    private $decoratedFactory;

    /** @var CatalogPromotionProviderInterface */
    private $catalogPromotionProvider;

    /** @var ServiceRegistryInterface */
    private $serviceRegistry;

    /** @var string */
    private $priceDocumentClass;

    /** @var string */
    private $decorationDocumentClass;

    /** @var string */
    private $appliedPromotionDocumentClass;

    /**
     * @param ProductDocumentFactoryInterface $decoratedFactory
     * @param CatalogPromotionProviderInterface $catalogPromotionProvider
     * @param ServiceRegistryInterface $serviceRegistry
     * @param string $priceDocumentClass
     * @param string $appliedPromotionDocumentClass
     * @param string $decorationDocumentClass
     */
    public function __construct(
        ProductDocumentFactoryInterface $decoratedFactory,
        CatalogPromotionProviderInterface $catalogPromotionProvider,
        ServiceRegistryInterface $serviceRegistry,
        $priceDocumentClass,
        $appliedPromotionDocumentClass,
        $decorationDocumentClass
    ) {
        $this->decoratedFactory = $decoratedFactory;
        $this->catalogPromotionProvider = $catalogPromotionProvider;
        $this->serviceRegistry = $serviceRegistry;
        $this->priceDocumentClass = $priceDocumentClass;
        $this->appliedPromotionDocumentClass = $appliedPromotionDocumentClass;
        $this->decorationDocumentClass = $decorationDocumentClass;
    }

    /**
     * {@inheritdoc}
     */
    public function createFromSyliusSimpleProductModel(
        ProductInterface $product,
        LocaleInterface $locale,
        ChannelInterface $channel
    ) {
        /** @var ProductDocument $productDocument */
        $productDocument = $this->decoratedFactory->createFromSyliusSimpleProductModel($product, $locale, $channel);

        if (!$product->isSimple()) {
            throw new UnsupportedFactoryMethodException(__METHOD__, sprintf(
                'Cannot create elastic search model from configurable product "%s".',
                $product->getCode()
            ));
        }

        /** @var ProductVariantInterface $productVariant */
        $productVariant = $product->getVariants()->first();

        $applicableCatalogPromotions = $this->catalogPromotionProvider->provide($channel, $productVariant);
        if (count($applicableCatalogPromotions) === 0) {
            return $productDocument;
        }

        $productDocument->setOriginalPrice($productDocument->getPrice());
        $price = $productDocument->getPrice()->getAmount();

        foreach ($applicableCatalogPromotions as $applicableCatalogPromotion) {
            /** @var CatalogDiscountActionCommandInterface $command */
            $command = $this->serviceRegistry->get($applicableCatalogPromotion->getDiscountType());

            $discount = $command->calculate($price, $channel, $applicableCatalogPromotion->getDiscountConfiguration());

            $price -= $discount;
        }

        /** @var PriceDocument $priceDocument */
        $priceDocument = new $this->priceDocumentClass();
        $priceDocument->setAmount($price);
        $priceDocument->setCurrency($productDocument->getOriginalPrice()->getCurrency());
        $productDocument->setPrice($priceDocument);

        $appliedPromotionDocuments = [];
        foreach ($applicableCatalogPromotions as $applicableCatalogPromotion) {
            /** @var AppliedPromotionDocument $appliedPromotionDocument */
            $appliedPromotionDocument = new $this->appliedPromotionDocumentClass();
            $appliedPromotionDocument->setCode($applicableCatalogPromotion->getCode());
            $appliedPromotionDocument->setDecorations(new Collection(
                array_map(function (CatalogPromotionDecoration $decoration) {
                    /** @var DecorationDocument $decorationDocument */
                    $decorationDocument = new $this->decorationDocumentClass();
                    $decorationDocument->setType($decoration->getType());
                    $decorationDocument->setConfiguration(json_encode($decoration->getConfiguration()));

                    return $decorationDocument;
                }, iterator_to_array($applicableCatalogPromotion->getDecorations()))
            ));

            $appliedPromotionDocuments[] = $appliedPromotionDocument;
        }

        $productDocument->setAppliedPromotions(new Collection($appliedPromotionDocuments));

        return $productDocument;
    }
}
