<?php

namespace App\Controller;

use Pimcore\Db;
use Pimcore\Tool;
use Pimcore\Model\DataObject;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\GSMBrands;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\Element;
use Pimcore\Model\Version;
use Pimcore\Model\DataObject\ClassDefinition\Data\ObjectBrick;
use Symfony\Component\HttpFoundation\JsonResponse;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class WooCommerceController extends FrontendController
{


    public function processWooCommerceUpdates($dataObject, $from, $to, $brandObject, $apiComCredentials, $externalProductId)
    {
        // print_r ($externalProductId); exit;
        $woocommerceProduct = array();
        // print_r ($externalProductId); exit;
        if (!empty($externalProductId)) {
            // echo "hello"; exit;
            $woocommerceProduct = $this->getProductIdBySku($dataObject, $apiComCredentials);

            // print_r ($woocommerceProduct); exit;
        }

        $updatedData = $this->getUpdatedData($from, $to, $brandObject, $apiComCredentials, $bigcommerceProduct = array(), $woocommerceProduct, $combinedUpdates = array());
        // $updatedData = json_encode($updatedData); exit;
        // print_r ($updatedData); exit;
        $updatedData = json_encode($updatedData, JSON_UNESCAPED_SLASHES);

        //  print_r($updatedData);   exit;
        $updateProduct = $this->updateProduct($dataObject, $apiComCredentials, $updatedData, $bigcommerceProduct = array(), $woocommerceProduct, $combinedUpdates = array());

        // print_r($updatedData); exit;
        return $updateProduct;
    }

    /**
     * Retrieve WooCommerce product details by product SKU.
     *
     * @param DataObject $dataObject        The data object representing the product.
     * @param array      $apiComCredentials The API credentials required for platform communication.
     *
     * @return array The product details retrieved from WooCommerce.
     * @throws \RuntimeException If there is an error during the API call.
     */
    public function getProductIdBySku($dataObject, $apiComCredentials)
    {
        $productType = $this->getProductType($dataObject);

        $id = $dataObject->getExternalProductID();

        if ($productType == 'products') {
            $url = $apiComCredentials['ApiUrl'] . '/' . $id;
        } elseif ($productType == 'variants') {
            $parentId = $dataObject->getParent()->getExternalProductID();
            $url = $apiComCredentials['ApiUrl'] . '/' . $parentId . '/' . 'variations' . '/' . $id;
        }

        $method = "PUT";
        $consumer_key = $apiComCredentials['getConsumer_key'];
        $consumer_secret = $apiComCredentials['getConsumer_secret'];
        $data = array();
        $woocommerceProduct = $this->remoteWooComCall($url, $method, $consumer_key, $consumer_secret, $data);
        //  print_r ($woocommerceProduct); exit;
        if ($woocommerceProduct) {
            return $woocommerceProduct;

        } else {
            return new Response("External Product Not Available");
        }
    }


    /**
     * Get the product type.
     * @param DataObject $dataObject The data object representing the product.
     *
     * @return string The product type (either 'product' or 'variant').
     * @throws \Exception If the product type is invalid.
     */
    private function getProductType($dataObject)
    {
        $productType = $dataObject->getProductType();

        if (empty($productType) || $productType == 'Simple' || $productType == 'Variable' || $productType == 'Variation') {
            $productType = 'Product';
        } elseif ($productType != 'Product' && $productType != 'Variant') {
            throw new \Exception('Invalid product type :' . $productType);
        }
        // echo $productType; exit;
        return strtolower($productType) . 's';
    }


    /**
     * Retrieves the latest updated data for a product based on its version.
     *
     * @param int    $from               VersionId of the product.
     * @param int    $to                 VersionId of the product target version.
     * @param Brand  $brandObject        The brand object associated with the product.
     * @param array  $bigcommerceProduct The BigCommerce product data.
     * @param array  $woocommerceProduct The WooCommerce product data.
     * @param array  $combinedUpdates    Combined updates data.
     * @param array  $apiComCredentials  The API credentials required for platform communication.
     *
     * @return array Updated product data.
     * @throws \RuntimeException If there is an error during the data retrieval process.
     */
    public function getUpdatedData($from, $to, $brandObject, $apiComCredentials, $bigcommerceProduct = array(), $woocommerceProduct = array(), $combinedUpdates = array())
    {
        // print_r ($combinedUpdates); exit;
        $platform = $brandObject->getPlatform();
        //  print_r ($platform); exit;
        $brandName = $brandObject->getBrandName();


        $dbResponse = $this->attributesFromDatabase(strtolower($brandName));
        //  print_r($dbResponse); exit;

        $version1 = \Pimcore\Model\Version::getById($from);
        $object1 = $version1->loadData();

        $version2 = \Pimcore\Model\Version::getById($to);
        $object2 = $version2->loadData();
        // print_r($object2); exit;
        $fields = $object2->getClass()->getFieldDefinitions();
        // print_r($fields); exit;
        $result = array();
        $productType = $this->getProductType($object2);
        // print_r($productType);  echo '--**--'; exit;

        foreach ($fields as $def => $value) {
            //  print_r($def);
            if ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields) {

            } elseif ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore) {
                // echo 'test-Classificationstore';
            } else if ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks) {
                // echo 'test-Objectbricks';
                $this->handleWooCommerceObjectBricks($def, $value, $object1, $object2, $result, $dbResponse, $woocommerceProduct, $apiComCredentials);
            } elseif ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections) {
                // echo 'test-Fieldcollections';
            } else {
                // echo 'test-OtherFields';
                $this->handleWooCommerceOtherFields($def, $value, $object1, $object2, $result, $dbResponse, $woocommerceProduct, $apiComCredentials);
            }
        }
        // print_r($result);   
        // exit;

        $resultNew = $this->flatArray($dbResponse, $result);
        //  print_r($dbResponse);  exit;
        // print_r($resultNew);  exit;
        $finalResult = array();
        // echo "test0"; exit;
        foreach ($result as $key1 => $value1) {
            // echo $key1;
            if (isset($resultNew[$key1][strtolower($platform)])) {

                // echo "test1"; exit;

                if (!empty($finalResult[$resultNew[$key1][strtolower($platform)]])) {
                    if (is_array($finalResult[$resultNew[$key1][strtolower($platform)]])) {
                        $finalResult[$resultNew[$key1][strtolower($platform)]] = array_merge($finalResult[$resultNew[$key1][strtolower($platform)]], $value1);
                    } else {
                        $finalResult[$resultNew[$key1][strtolower($platform)]] = $value1;
                    }
                } else {
                    $finalResult[$resultNew[$key1][strtolower($platform)]] = $value1;
                }
            }

            if ($key1 == 'meta_data') {
                // echo "test2"; exit;
                $myMeta = array();
                // print_r($finalResult[$resultNew[$key1][strtolower($platform)]]); 
                foreach ($finalResult[$resultNew[$key1][strtolower($platform)]] as $metakey) {
                    //   print_r ($key1); 
                    //  print_r($metakey['key']);
                    // print_r($dbResponse);
                    //   print_r ($platform); exit;
                    // echo "test2"; exit;

                    $mykey = array_search($metakey['key'], array_column($dbResponse, 'pimcore'));


                    //   print_r($dbResponse[$mykey]); 
                    $myMeta[] = array(
                        'key' => $dbResponse[$mykey][strtolower($platform)],
                        'value' => $metakey['value']
                    );

                }
                //   print_r ($myMeta); exit;
                $finalResult[$resultNew[$key1][strtolower($platform)]] = $myMeta;
            }
            // echo "test4";  exit;
            // exit;
        }
        // echo "test3";  exit;
        //  print_r($finalResult); exit;

        return $finalResult;

    }

    /**
     * Filters an array based on keys present in another array.
     *
     * @param array $dbResponse The array to filter.
     * @param array $result     An array containing keys for filtering.
     *
     * @return array The filtered array.
     */

    function flatArray($dbResponse, $result)
    {

        $filteredArray = array();
        foreach ($dbResponse as $key => $value) {

            if (array_key_exists($value['pimcore'], $result)) {
                //print_r($value['pimcore']);

                $filteredArray[$value['pimcore']] = $value;
            }
        }
        return $filteredArray;
    }


    private function handleWooCommerceObjectBricks($def, $value, $object1, $object2, &$result, $dbResponse, $woocommerceProduct, $apiComCredentials)
    {
        // print_r($woocommerceProduct); exit;

        $fieldName = $value->getName();
        // print_r ($fieldName); exit;

        $getter = 'get' . $fieldName;
        $oldObjectFieldValue = $object1->{$getter}();
        $newObjectFieldValue = $object2->{$getter}();

        $brickDataArray1 = array();
        $brickDataArray2 = array();
        foreach ($value->getAllowedTypes() as $allowedType) {
            $customFields = [];
            $objectBrick = 'get' . $allowedType;


            if (!empty($newObjectFieldValue->{$objectBrick}())) {
                $brickNew = $newObjectFieldValue->{$objectBrick}();
                $allowedData = DataObject\Objectbrick\Definition::getByKey($allowedType)->getFieldDefinitions();
                foreach ($allowedData as $allowedField) {
                    // $brickKey1 = $allowedField->getName();
                    $brickKey1 = $allowedField->getTitle();

                    $brickValue1 = $brickNew->getValueForFieldName($allowedField->getName());
                    // print_r ($brickValue1); //exit;
                    $brickDataArray1[$brickKey1] = $brickValue1;
                    // print_r ($brickDataArray1); exit;
                }
            } else if (!empty($oldObjectFieldValue->{$objectBrick}())) {
                $brickOld = $oldObjectFieldValue->{$objectBrick}();
                $allowedDataOld = DataObject\Objectbrick\Definition::getByKey($allowedType)->getFieldDefinitions();
                foreach ($allowedDataOld as $allowedFieldOld) {
                    // $brickKey2 = $allowedFieldOld->getName();
                    $brickKey2 = $allowedFieldOld->getTitle();
                    $brickValue2 = $brickOld->getValueForFieldName($allowedFieldOld->getName());
                    $brickDataArray2[$brickKey2] = $brickValue2;

                }
            }
        }

        $resultArray = array_diff_assoc($brickDataArray1, $brickDataArray2);
        // print_r ($resultArray); //exit;

        if (empty($resultArray)) {
            $resultArray = array_diff_assoc($brickDataArray2, $brickDataArray1);
        }
        // print_r ($resultArray); exit;


        $productType = $this->getProductType($object2);
        // print_r ($productType); exit; 
        $brickDataArray1 = array();


        if ($object2->getParent()->getType() == 'object' && $def == 'VariantAttributes') {
            // print_r ($resultArray); exit;

            foreach ($resultArray as $brickKey1 => $brickValue1) {
                if (!empty($brickKey1 && $brickValue1)) { // Check if value is not empty
                    $attributeOptions[] = array(
                        "option_name" => $brickKey1, // Assign key to 'option_name'
                        "option_value" => $brickValue1, // Assign value to 'option_value'
                    );
                }
            }

            // $attributeOptions = array( $brickDataArray1[$brickKey1]);
            // print_r ($attributeOptions); exit;
            $externalProductId = $object2->getExternalProductID();
            // echo $externalProductId;
            if ($productType == 'variants') {
                // echo "TEST 1"; exit;
                if ($externalProductId == "" || ($externalProductId !== "" && $attributeOptions)) {
                    // echo "TEST 2"; exit;
                    $this->variantProductCreation($attributeOptions, $object2, $apiComCredentials, $result);
                }
                //  print_r($result);  exit;
            }

        } //exit;

        // print_r($bigcommerceProduct); exit;
        if ($productType != 'variants' && !empty($resultArray) && !empty($bigcommerceProduct)) {
            // echo '-123-';
            $customParam = $bigcommerceProduct['data']['custom_fields'];

            foreach ($resultArray as $key => $value) {

                $res = array_column($dbResponse, 'pimcore');

                $keyval12 = array_search($key, array_column($dbResponse, 'pimcore'));
                if ($keyval12 !== false) {
                    $Key1 = $dbResponse[$keyval12]['bigcommerce'];

                    if (in_array($Key1, array_column($customParam, 'name'))) {
                        $keyval = array_search($Key1, array_column($customParam, 'name'));
                        if (!empty($value)) {
                            $result["Specifications"][] = [
                                "id" => $customParam[$keyval]["id"],
                                "name" => $Key1,
                                "value" => $value,
                            ];
                        }
                    } else {
                        if (!empty($value)) {
                            $result["Specifications"][] = [
                                "name" => $Key1,
                                "value" => $value,
                            ];
                        }
                    }
                }

            }
            //   print_r($result); exit;
        } else if (!empty($resultArray) && empty($bigcommerceProduct)) {

            foreach ($resultArray as $key => $value) {
                $res = array_column($dbResponse, 'pimcore');

                $keyval12 = array_search($key, array_column($dbResponse, 'pimcore'));

                if ($keyval12 !== false && isset($dbResponse[$keyval12]['bigcommerce'])) {
                    $Key1 = $dbResponse[$keyval12]['bigcommerce'];

                    if (!empty($value)) {
                        $result["Specifications"][] = [
                            "name" => $Key1,
                            "value" => $value,
                        ];
                    }
                }
            }

        }
        //  print_r($result);  exit;
    }

    /**
     * Handles processing of other fields for BigCommerce product.
     *
     * @param string $def                 The definition key.
     * @param mixed  $value               The value associated with the definition key.
     * @param object $object1             The first object.
     * @param object $object2             The second object.
     * @param array  $result              The result array to be modified.
     * @param array  $dbResponse          The database response array.
     * @param array  $bigcommerceProduct  The BigCommerce product data.
     * @param array  $apiComCredentials   The API credentials for communication with BigCommerce.
     * @return array                      The modified result array.
     */
    private function handleWooCommerceOtherFields($def, $value, $object1, $object2, &$result, $dbResponse, $woocommerceProduct, $apiComCredentials)
    {
        // print_r ($result); //exit;
        $externalProductId = trim($object2->getExternalProductID());
        $fieldName = $value->getName();
        // print_r($fieldName); //exit;
        // print_r($object2->getValueForFieldName($fieldName)); exit;
        $newObjectFieldValue = $object2->getValueForFieldName($fieldName) != "" || $object2->getValueForFieldName($fieldName) != "" ? $object2->getValueForFieldName($fieldName) : null;

        $v1 = $value->getVersionPreview($newObjectFieldValue);
        $oldObjectFieldValue = $object1->getValueForFieldName($fieldName) != "" || $object1->getValueForFieldName($fieldName) != "" ? $object1->getValueForFieldName($fieldName) : null;
        $v2 = $value->getVersionPreview($oldObjectFieldValue);

        // print_r($object2 ->getExternalProductID()); exit;
        if (!empty($externalProductId)) {

            // echo "ExternalProductId is present"; exit;
            if (!is_null($value) && $value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\EqualComparisonInterface && !$value->isEqual($newObjectFieldValue, $oldObjectFieldValue)) {
                //   echo print_r($fieldName); //exit;
                if ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\QuantityValue) {
                    // echo "$def";
                    $getter = 'get' . ucfirst($def);
                    // print_r ($getter); exit;
                    $fieldName = $object2->$getter();
                    // print_r ($fieldName); //exit;
                    $quantityValue = $value->getDataForEditMode($fieldName, $object2);
                    // print_r ($quantityValue); //exit;
                    // $result[$def] = $quantityValue['value'];
                    // print_r ($result); exit;

                    if (in_array($def, ['Weight', 'Length', 'Width', 'Height'])) {
                        if ($def == 'Weight') {
                            // echo "weight";
                            $result['Weight'] = (string) $quantityValue['value'] ?: "0";
                            // print_r ($result); //exit;
                        } else {
                            // echo "height"; //exit;
                            $result['dimensions'][strtolower($def)] = (string) $quantityValue['value'] ?: "0";
                            // print_r ($result); //exit;
                        }
                    }
                } elseif ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\BooleanSelect) {
                    // echo "test";

                    $boolValue = (bool) $v1;
                    // print_r ($boolValue);
                    // exit;
                    if ($boolValue == true) {  //echo true;
                        $result[$fieldName] = true;
                    } else {  //echo false;
                        $result[$fieldName] = false;
                    }
                    // print_r ($result);
                    // exit;
                }

                //Category
                elseif ($fieldName == "Category" || $fieldName == "SubCategory" || $fieldName == 'SubSubCategory') {

                    $url = $apiComCredentials['ApiUrl'] . '/categories';
                    $method = "GET";
                    $consumer_key = $apiComCredentials['getConsumer_key'];
                    $consumer_secret = $apiComCredentials['getConsumer_secret'];
                    $data = array();
                    $allCategories = $this->remoteWooComCall($url, $method, $consumer_key, $consumer_secret, $data);

                    if (!isset($result['Category'])) {
                        $result['Category'] = array();
                    }

                    if (isset($allCategories) && is_array($allCategories)) {
                        foreach ($allCategories as $category) {

                            $categoryId = $category['id'];
                            $parentId = $category['parent'];
                            $name = $category['name'];

                            if (strtolower($v1) == strtolower($name)) {
                                // $result['Category'][] = $categoryId;
                                $result['Category'][] = array(
                                    'id' => $categoryId
                                );
                                if ($parentId !== 0) {
                                    $result['Category'][] = $parentId;
                                }
                            }
                        }

                    }

                } elseif ($fieldName == "ThumbnailImage" || $fieldName == "ImageGallery") {
                    // Prepare arrays for ImageGallery and ThumbnailImage
                    $imageGalleryArray = array();
                    $thumbnailImageArray = array();
                    $thumbnailImageArray[] = array(
                        // "src"=> $hostURL.$imagePath,
                        // "https://cdn.pixabay.com/photo/2020/11/01/17/53/coronavirus-5704493_1280.png",
                        // "src" => "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/01/coyote-light-batteries-3-pack.jpg",
                    );

                    // Assign ImageGallery data using a loop
                    $imageUrls = [
                        // "src" => "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/01/coyote-light-batteries-3-pack.jpg",
                        // "https://cdn.pixabay.com/photo/2020/11/01/17/53/coronavirus-5704493_1280.png",
                        // "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/02/CoyoteLightHandle_Large_01_21-100x100.jpg",
                        // Add more URLs here as needed
                    ];

                    foreach ($imageUrls as $url) {
                        $imageGalleryArray[] = array(
                            "src" => $url,
                        );
                    }
                    $result['ImageGallery'] = array_merge($thumbnailImageArray, $imageGalleryArray);
                } else {
                    if ($fieldName == 'ProductType') {
                        if ($v1 == 'Simple' || $v1 == 'Variable' || $v1 == 'Variation') {
                            $result['ProductType'] = 'simple';
                        }
                    } elseif ($fieldName == "CurrentStock" || $fieldName == "LowStockLevel") {
                        $result['manage_stock'] = true;
                        if ($fieldName == "CurrentStock") {
                            $result['CurrentStock'] = $v1;
                        }
                        if ($fieldName == "LowStockLevel") {
                            $result['LowStockLevel'] = $v1;
                        }
                    } elseif ($fieldName == "ManufacturerPartNumber") {
                        $result['gpf_data'] = array(
                            'mpn' => $v1
                        );
                    } elseif ($fieldName == "UPC") {
                        $result['meta_data'][] = array(
                            "key" => $fieldName,
                            'value' => $v1
                        );
                    }

                    //TAGS
                    elseif ($fieldName == "Tags") {
                        $tags = explode(",", $v1); // Split $v1 by commas
                        foreach ($tags as $tag) {
                            $result['tags'][] = array(
                                "name" => trim($tag) // Trim whitespace from each tag
                            );
                        }
                    } elseif (in_array($fieldName, ['TagLine', 'TagLine2', 'IsThisNewProduct', 'ComingSoon', 'Specs', 'TaxProviderTaxCode', 'VideoURL', 'VideoURL2', 'Envelope', 'DeclaredValue', 'AffiliateRateType', 'AffiliateRate', 'DisableInInventoryFeed', 'DisableReferrals'])) {
                        $result['meta_data'][] = array(
                            "key" => "$fieldName",
                            'value' => $v1,
                        );
                    } else {
                        $result[$fieldName] = $v1;
                    }


                }
                if (isset($result['MSRP'])) {
                    $result['MSRP'] = $result['MSRP'] != "" ? (string) $result['MSRP'] : "0";
                    $result['RegularPrice'] = (string) $result['MSRP'];
                }
                if (isset($result['SalePrice'])) {
                    $result['SalePrice'] = (string) $result['SalePrice'];
                }

                //   print_r ($result); exit;
            }
        } else {
            // echo "ExternalProductId is not present";
            if (!empty($v1)) {

                if ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\QuantityValue) {
                    // echo "$def";
                    $getter = 'get' . ucfirst($def);
                    $fieldName = $object2->$getter();
                    $quantityValue = $value->getDataForEditMode($fieldName, $object2);
                    // $result[$def] = $quantityValue['value'];

                    if (in_array($def, ['Weight', 'Length', 'Width', 'Height'])) {
                        if ($def == 'Weight') {
                            $result['Weight'] = (string) $quantityValue['value'] ?: "0";
                        } else {
                            $result['dimensions'][strtolower($def)] = (string) $quantityValue['value'] ?: "0";
                        }
                    }

                } elseif ($value instanceof \Pimcore\Model\DataObject\ClassDefinition\Data\BooleanSelect) {

                    $boolValue = (bool) $v1;
                    if ($boolValue == true) {  //echo true;
                        $result[$fieldName] = true;
                    } else {  //echo false;
                        $result[$fieldName] = false;
                    }
                    // print_r ($result);
                    // exit;
                }

                //Category
                elseif ($fieldName == "Category" || $fieldName == "SubCategory" || $fieldName == 'SubSubCategory') {

                    // print_r($fieldName); exit;
                    $url = $apiComCredentials['ApiUrl'] . '/categories';
                    $method = "GET";
                    $consumer_key = $apiComCredentials['getConsumer_key'];
                    $consumer_secret = $apiComCredentials['getConsumer_secret'];
                    $data = array();
                    $allCategories = $this->remoteWooComCall($url, $method, $consumer_key, $consumer_secret, $data);


                    if (!isset($result['Category'])) {
                        $result['Category'] = array();
                    }

                    if (isset($allCategories) && is_array($allCategories)) {
                        foreach ($allCategories as $category) {
                            $categoryId = $category['id'];
                            $parentId = $category['parent'];
                            $name = $category['name'];

                            if (strtolower($v1) == strtolower($name)) {
                                // $result['Category'][] = $categoryId;
                                $result['Category'][] = array(
                                    "id" => $categoryId
                                );
                                if ($parentId !== 0) {
                                    $result['Category'][] = $parentId;
                                }
                            }
                        }
                    }

                }


                //For Images
                // elseif ($fieldName == "ThumbnailImage" || $fieldName == "ImageGallery") {
                //     if ($fieldName == "ThumbnailImage") {
                //         echo "Thumbnail";
                //         $thumbnailImage = $object2->getThumbnailImage();
                //         $hostURL = \Pimcore\Tool::getHostUrl();
                //         if ($thumbnailImage instanceof \Pimcore\Model\DataObject\Data\Hotspotimage) {
                //             $image = $thumbnailImage->getImage();
                //             if ($image instanceof \Pimcore\Model\Asset\Image) {
                //                 $imagePath = $image->getFullPath();
                //                 // Add ThumbnailImage URL to thumbnailImageArray
                //                 $result['ImageGallery'][] = array(
                //                     "src" => $hostURL . $imagePath,
                //                     // "src" => "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/01/coyote-light-batteries-3-pack.jpg",
                //                 );
                //             }


                //         }


                //     }

                //     if ($fieldName == "ImageGallery") {
                //         // echo "Image Gallery";
                //         $GalleryImage = $object2->getImageGallery();
                //         $hostURL = \Pimcore\Tool::getHostUrl();
                //         if (!empty($GalleryImage)) {
                //             foreach ($GalleryImage as $thumbnailImage) {
                //                 if ($thumbnailImage instanceof \Pimcore\Model\DataObject\Data\Hotspotimage) {
                //                     $image = $thumbnailImage->getImage();
                //                     if ($image instanceof \Pimcore\Model\Asset\Image) {
                //                         $imagePath = $image->getFullPath();
                //                         $result['ImageGallery'][] = array(
                //                             "src" => $hostURL . $imagePath,
                //                         // "src" => "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/02/CoyoteLightHandle_Large_01_21-100x100.jpg",
                //                         );
                //                     }
                //                 }
                //             }

                //         }
                //     }
                // } 

                //Static Method With Loop For Image Gallery
                // elseif ($fieldName == "ThumbnailImage" || $fieldName == "ImageGallery") {
                //     // Prepare arrays for ImageGallery and ThumbnailImage
                //     $imageGalleryArray = array();
                //     $thumbnailImageArray = array();
                //             $thumbnailImageArray[] = array(
                //                 // "src"=> $hostURL.$imagePath,
                //         // "src" => "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/01/coyote-light-batteries-3-pack.jpg",
                //     );

                //     // Assign ImageGallery data using a loop
                //     $imageUrls = [
                //         // "https://cdn.pixabay.com/photo/2020/11/01/17/53/coronavirus-5704493_1280.png",
                //         // "https://dev.coyotelight.com/wp-content/uploads/sites/12/2019/02/CoyoteLightHandle_Large_01_21-100x100.jpg",
                //         // Add more URLs here as needed
                //     ];

                //     foreach ($imageUrls as $url) {
                //         $imageGalleryArray[] = array(
                //             "src" => $url,
                //         );
                //     }
                //     $result['ImageGallery'] = array_merge($thumbnailImageArray, $imageGalleryArray);
                // }
                elseif ($fieldName == "ThumbnailImage" || $fieldName == "ImageGallery") {
                    // Prepare arrays for ImageGallery and ThumbnailImage
                    $imageGalleryArray = array();
                    $thumbnailImageArray = array();

                    $thumbnailImage = $object2->getThumbnailImage();
                    $galleryImage = $object2->getImageGallery();
                    $hostURL = \Pimcore\Tool::getHostUrl();
                    if (!empty($thumbnailImage)) {
                        echo "THUMBNAIL";
                        if ($thumbnailImage instanceof \Pimcore\Model\DataObject\Data\Hotspotimage) {
                            $image = $thumbnailImage->getImage();
                            if ($image instanceof \Pimcore\Model\Asset\Image) {
                                $imagePath = $image->getFullPath();
                                $thumbnailImageArray[] = array(
                                    "src" => $hostURL . $imagePath,
                                );
                            }
                        }
                    }

                    if (!empty($galleryImage)) {
                        echo "IMAGE GALLERY";
                        foreach ($galleryImage as $thumbnailImage) {
                            if ($thumbnailImage instanceof \Pimcore\Model\DataObject\Data\Hotspotimage) {
                                $image = $thumbnailImage->getImage();
                                if ($image instanceof \Pimcore\Model\Asset\Image) {
                                    $imagePath = $image->getFullPath();
                                    $imageGalleryArray[] = array(
                                        "src" => $hostURL . $imagePath,
                                    );
                                }
                            }
                        }

                    }
                    // Ensure ThumbnailImageArray has an empty array if it's empty
                    if (empty($thumbnailImageArray)) {
                        $thumbnailImageArray[] = array();
                    }

                    // Merge ThumbnailImage array to the beginning of ImageGallery array
                    $result['ImageGallery'] = array_merge($thumbnailImageArray, $imageGalleryArray);
                } else {
                    if ($fieldName == 'ProductType') {
                        if ($v1 == 'Simple' || $v1 == 'Variable' || $v1 == 'Variation') {
                            $result['ProductType'] = 'simple';
                        }
                    } elseif ($fieldName == "CurrentStock" || $fieldName == "LowStockLevel") {
                        $result['manage_stock'] = true;
                        if ($fieldName == "CurrentStock") {
                            $result['CurrentStock'] = $v1;
                        }
                        if ($fieldName == "LowStockLevel") {
                            $result['LowStockLevel'] = $v1;
                        }
                    } elseif ($fieldName == "ManufacturerPartNumber") {
                        // echo 'ManufacturerPartNumber';
                        $result['gpf_data'] = array(
                            'mpn' => $v1
                        );
                        // print_r($result); exit;
                    } elseif ($fieldName == "UPC") {
                        // echo 'ManufacturerPartNumber';
                        $result['meta_data'][] = array(
                            // "key" => strtolower($fieldName),
                            "key" => "UPC",
                            'value' => $v1
                        );
                        // print_r($result); exit;
                    } elseif (in_array($fieldName, ['TagLine', 'TagLine2', 'IsThisNewProduct', 'ComingSoon', 'Specs', 'TaxProviderTaxCode', 'VideoURL', 'VideoURL2', 'Envelope', 'DeclaredValue', 'AffiliateRateType', 'AffiliateRate', 'DisableInInventoryFeed', 'DisableReferrals'])) {
                        $result['meta_data'][] = array(
                            "key" => "$fieldName",
                            'value' => $v1,
                        );
                    } elseif ($fieldName == "Tags") {
                        $tags = explode(",", $v1); // Split $v1 by commas
                        foreach ($tags as $tag) {
                            $result['tags'][] = array(
                                "name" => trim($tag) // Trim whitespace from each tag
                            );
                        }
                    } else {
                        $result[$fieldName] = $v1;
                        //  echo "else";
                    }
                }

                if (isset($result['MSRP'])) {
                    $result['MSRP'] = $result['MSRP'] != "" ? (string) $result['MSRP'] : "0";
                    $result['RegularPrice'] = (string) $result['MSRP'];
                }
                if (isset($result['SalePrice'])) {
                    $result['SalePrice'] = (string) $result['SalePrice'];
                }
                if (isset($result['FixedShippingPrice'])) {
                    $result['FixedShippingPrice'] = (string) $result['FixedShippingPrice'];
                }
                if (!isset($result['ProductType'])) {
                    $result['ProductType'] = 'simple';

                }

            }
        }

        // print_r($result);  
        // exit;
        return $result;
    }



    //NEW

    /**
     * Creates variant products based on pattern options.
     *
     * @param array  $attributeOptions      The pattern options for variant product creation.
     * @param object $object2             The object representing the variant product.
     * @param array  $apiComCredentials   The API credentials for communication with BigCommerce.
     * @param array  $result              The result array to be modified with variant product data.
     * @return void
     */
    function variantProductCreation($attributeOptions, $object2, $apiComCredentials, &$result)
    {
        // print_r ($result); exit;
        $varId = $object2->getParent()->getId();
        $variantObject = DataObject::getById($varId);
        $varObjectId = $variantObject->getExternalProductID();

        $updatedData = array();
        $url = $apiComCredentials['ApiUrl'] . '/' . $varObjectId;
        $consumerKey = $apiComCredentials['getConsumer_key'];
        $consumerSecret = $apiComCredentials['getConsumer_secret'];
        $method = "GET";
        $parentObject = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData);

        if ($variantObject instanceof \Pimcore\Model\DataObject\AllProducts) {
            echo "VARIANT";

            $url = $apiComCredentials['ApiUrl'] . '/' . $varObjectId;
            $method = "PUT";
            $productType = array('type' => "variable");
            $updatedData = json_encode($productType);
            $response = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData);

            $updatedData = array();
            $method = "GET";
            $url = $apiComCredentials['ApiUrl'] . '/attributes';
            $variantObject = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData);

            $comparedVariantData = $this->compareDisplayNameAndLabel($variantObject, $attributeOptions, $apiComCredentials, $parentObject);
            // print_r ($comparedVariantData); exit;
            $variantData = array(
                'id' => $comparedVariantData['id'],
                'option' => $comparedVariantData['options'][0],
            );

            $result['attributes'][] = $variantData;
            // $ab = json_encode($result);
            // print_r ($ab); exit;


        }
    }

    /**
     * Compares display names and labels to determine variant option values.
     *
     * @param array  $variantObject   The variant object retrieved from BigCommerce.
     * @param array  $attributeOptions  The pattern options for variant product creation.
     * @param string $url             The URL for making API calls to BigCommerce.
     * @param string $apiToken        The API token for authentication with BigCommerce.
     * @return array The variant option values.
     */
    function compareDisplayNameAndLabel($variantObject, $attributeOptions, $apiComCredentials, $parentObject)
    {
        // print_r ($parentObject); exit;

        $dataColumn = array_column($variantObject, 'name');
        // print_r ($variantObject); exit;

        $optionValues = array();
        foreach ($attributeOptions as $options) {
            // print_r ($options); //exit;
            $key = array_search($options['option_name'], $dataColumn);

            if (!empty($key)) {
                echo "KEY EXIST";

                // If attribute option_name exists
                $parentOptionData = $variantObject[$key];
                $parentOptionId = $parentOptionData['id'];

                // Fetch existing option values from the WooCommerce API
                $method = "GET";
                $url = $apiComCredentials['ApiUrl'] . '/attributes/' . $parentOptionId . '/terms';
                $consumerKey = $apiComCredentials['getConsumer_key'];
                $consumerSecret = $apiComCredentials['getConsumer_secret'];
                $optionValuesExist = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, []);
                // print_r ($optionValuesExist); exit;

                $optionsValueFind = array_search($options['option_value'], array_column($optionValuesExist, 'name'));

                // Define function to merge options
                function mergeOptions($parentObject, $optionValues, $parentOptionData)
                {
                    $finalOptions = !empty($parentObject['attributes'][0]['options'])
                        ? array_merge($parentObject['attributes'][0]['options'], $optionValues['options'])
                        : $optionValues['options'];

                    return [
                        "id" => $parentOptionData['id'],
                        "options" => $finalOptions,
                        'visible' => true,
                        'variation' => true
                    ];
                }

                if ($optionsValueFind !== false || $optionsValueFind === 0) {
                    echo "OPTION & VALUE EXIST";
                    $optionValues = [
                        'id' => $parentOptionData['id'],
                        'options' => [$options['option_value']],
                        'visible' => true,
                        'variation' => true
                    ];
                } else {
                    echo "VALUE NOT EXIST";
                    $newOptionValue = $this->createOptionValue($url, $consumerKey, $consumerSecret, $options);
                    $optionValues = [
                        'id' => $parentOptionData['id'],
                        'options' => [$newOptionValue['name']],
                        'visible' => true,
                        'variation' => true
                    ];
                }

                $finalAttributeList = mergeOptions($parentObject, $optionValues, $parentOptionData);
                // print_r ($finalAttributeList); exit;
                $updatedData["attributes"][] = $finalAttributeList;
                $updatedData = json_encode($updatedData);
                $url = $apiComCredentials['ApiUrl'] . '/' . $parentObject['id'];
                $method = "PUT";
                $response = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData);
            } else {
                echo "NEW OPTION & VALUE";
                // If attribute's name and it's options does not exist
                $newOptionAndValue = $this->createVariantOptionAndOptionValue($apiComCredentials, $variantObject, $attributeOptions);
                $optionValues = array(
                    'id' => $newOptionAndValue[0]['id'],
                    'options' => array($newOptionAndValue[1]['name']),
                    'visible' => true,
                    'variation' => true
                );

                $updatedData["attributes"][] = $optionValues;
                $updatedData = json_encode($updatedData);
                $url = $apiComCredentials['ApiUrl'] . '/' . $parentObject['id'];
                $method = "PUT";
                $consumerKey = $apiComCredentials['getConsumer_key'];
                $consumerSecret = $apiComCredentials['getConsumer_secret'];
                $response = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData);

            }
        }
        // print_r ($optionValues); exit;
        return $optionValues;
    }


    /**
     * Creates an option value for a variant option.
     *
     * @param string $url             The URL for the API call.
     * @param string $apiToken        The API token for authentication.
     * @param int    $variantOptionId The ID of the variant option.
     * @param string $labelName       The label of the option value.
     * @return array The response from the API call containing the created option value.
     */
    function createOptionValue($url, $consumerKey, $consumerSecret, $options)
    {

        $method = "POST";
        $optionData = [
            // 'name' => $options[0]['option_value']
            'name' => $options['option_value']
        ];

        $opData = json_encode($optionData);
        $newOptionValue = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $opData);
        // print_r ($newOptionValue); //exit;
        return $newOptionValue;


    }


    /**
     * Creates a variant option and its corresponding option value.
     *
     * @param string $url       The URL for the API call.
     * @param string $apiToken  The API token for authentication.
     * @param string $displayName The display name of the variant option.
     * @param string $label     The label of the option value.
     * @return array The response from the API call containing the created variant option and option value.
     */
    function createVariantOptionAndOptionValue($apiComCredentials, $variantObject, $attributeOptions)
    {
        // $updatedData =  array_column($attributeOptions, 'option_name');
        // print_r ($attributeOptions[0]['option_value']); exit;
        $newOption = [
            'name' => $attributeOptions[0]['option_name']
        ];
        $newOptionData = json_encode($newOption);

        if ($variantObject) {
            // $updatedData = array();


            $method = "POST";
            $url = $apiComCredentials['ApiUrl'] . '/attributes';
            $consumerKey = $apiComCredentials['getConsumer_key'];
            $consumerSecret = $apiComCredentials['getConsumer_secret'];
            $newOption = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $newOptionData);

            $parentOptionId = $newOption['id'];


            $method = "POST";
            $url = $apiComCredentials['ApiUrl'] . '/attributes/' . $parentOptionId . '/terms';
            $newValue = [
                'name' => $attributeOptions[0]['option_value']
            ];
            $newValueData = json_encode($newValue);
            $newValue = $this->remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $newValueData);
            // print_r($newValue); //exit;


            $newOptionAndValue = [$newOption, $newValue];
            $newOptionAndValueData = json_encode($newOptionAndValue);

        }

        return $newOptionAndValue;
    }

    //NEW


    /**
     * Retrieves cross-system attributes from the database for a given brand.
     *
     * @param string $brandName The name of the brand.
     *
     * @return array An array containing cross-system attributes for the brand.
     */
    function attributesFromDatabase($brandName)
    {

        $connection = Db::getConnection();
        $query = $connection->createQueryBuilder();

        $query->select('*')
            ->from('cross_system_attributes')
            ->where('brand = :brandName')
            ->setParameter('brandName', $brandName);
        // print_r($brandName);

        $dbResponse = $query->execute()->fetchAll();
        // print_r($dbResponse); exit;
        return $dbResponse;
    }

    /** 
     * @Route("/create_wc_product", name="Create WC Product", methods={"GET"})
     */
    public function updateProduct($dataObject, $apiComCredentials, $updatedData, $bigcommerceProduct = array(), $woocommerceProduct = array(), $combinedUpdates = array())
    {
        // print_r ($updatedData); exit;
        $consumer_key = $apiComCredentials['getConsumer_key'];
        $consumer_secret = $apiComCredentials['getConsumer_secret'];

        $externalProductId = trim($dataObject->getExternalProductID());
        $url = $apiComCredentials['ApiUrl'] . '/' . $externalProductId;
        $method = $externalProductId != "" ? "PUT" : "POST";
        $productType = $this->getProductType($dataObject);
        if ($productType == 'variants') {

            $varId = $dataObject->getParent()->getId();
            $variantObject = DataObject::getById($varId);
            $parentObjectId = $variantObject->getExternalProductID();

            if ($variantObject instanceof \Pimcore\Model\DataObject\AllProducts) {
                if ($externalProductId) {
                    //Update Condition
                    echo "ID Exist";
                    $url = $apiComCredentials['ApiUrl'] . '/' . $parentObjectId . '/' . 'variations' . '/' . $externalProductId;

                } else {
                    //Create Condition
                    $url = $apiComCredentials['ApiUrl'] . '/' . $parentObjectId . '/' . 'variations';
                }

            }
        }

        print_r($url);
        print_r($method);
        print_r($updatedData); //exit;
        $response = $this->remoteWooComCall($url, $method, $consumer_key, $consumer_secret, $updatedData);


        if (isset($response['id'])) {
            $externalId = $response['id'];
            if ($response && $externalProductId == "") {
                $dataObject->setExternalProductID($externalId);
                $dataObject->save();
            }
            return new JsonResponse($response);
        } else {
            echo "ELSE";
            return new JsonResponse(['error' => 'Product creation/update failed'], 500);
        }
        return new jsonResponse($response);
    }


    /**
     * Makes a remote call to WooCommerce API.
     *
     * @param string $url      The URL for the API call.
     * @param string $method   The HTTP method for the request.
     * @param string $consumerKey The consumer key for authentication.
     * @param string $consumerSecret The consumer secret for authentication.
     * @param mixed  $data     The data to be sent with the request (optional).
     * @return array The decoded response from the API call.
     */
    function remoteWooComCall($url, $method, $consumerKey, $consumerSecret, $updatedData)
    {

        // print_r($url) ; 
        // print_r($updatedData);  //exit;
        $auth = base64_encode($consumerKey . ':' . $consumerSecret);

        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic $auth",
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS => $updatedData,
            CURLOPT_VERBOSE => true,
        ];

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        // print_r($response); //exit;
        $info = curl_getinfo($curl);
        $decodedResponse = json_decode($response, true);

        if (curl_errno($curl)) {
            echo 'Error: ' . curl_error($curl);
        }

        curl_close($curl);  //exit;
        return $decodedResponse;
        // print_r ($decodedResponse); //exit;
    }

}
