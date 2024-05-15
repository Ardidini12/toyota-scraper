<?php

namespace App\Http\Controllers;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;

class ToyotaScrapeController extends Controller
{
    public function fetchOffers()
    {
        $base_url = 'https://www.buyatoyota.com';
        $url = $base_url . '/greaterny/offers/?filters=lease&limit=27';
        $headers = $this->getHeaders();

        $html = $this->curlRequest($url, $headers);
        $offerLinks = $this->getAllOfferLinks($html);
        if (empty($offerLinks)) {
            echo "No offer links found.";
            return;
        }

        $results = [];
        $uniqueResults = [];

        foreach ($offerLinks as $link) {
            $absoluteLink = $this->ensureAbsoluteUrl($link, $base_url);
            $offerHtml = $this->curlRequest($absoluteLink, $headers);
            $details = $this->extractOfferDetails($offerHtml);

            $year = 2024;
            $make = 'Toyota';
            $model = $this->extractModel($details['title']);
            $trim = $this->extractTrim($details['terms']);
            $msrp = $this->extractMsrp($details['disclaimer']);
            $monthly_payment = $this->extractMonthlyPayment($details['lease_info']);
            $term = $this->extractTerm($details['lease_info']);
            $due_at_signing = $this->extractDueAtSigning($details['lease_info']);
            $annual_miles = 10000;
            $acquisition_fee = 650;
            $residual_value = $this->extractResidualValue($details['disclaimer']);
            $mileage_overage = 0.15;
            $disposition_fee = 350;
            $end_date = $this->extractEndDate($details['disclaimer']);
            $capitalized_cost = $this->extractCapitalizedCost($details['disclaimer']);

            // Calculated values
            $monthly_payment_zero = $this->calculateMonthlyPaymentZero($monthly_payment, $due_at_signing, $term);
            $residual_perc = $this->calculateResidualPercentage($residual_value, $msrp);
            $money_factor = $this->calculateMoneyFactor($monthly_payment, $capitalized_cost, $residual_value, $term);
            $interest_rate = round($money_factor * 2400, 1);

            // Assemble all data into an array
            $result = [
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'trim' => $trim,
                'msrp' => $msrp,
                'monthly_payment' => $monthly_payment,
                'monthly_payment_zero' => $monthly_payment_zero,
                'term' => $term,
                'due_at_signing' => $due_at_signing,
                'annual_miles' => $annual_miles,
                'acquisition_fee' => $acquisition_fee,
                'residual_value' => $residual_value,
                'residual_perc' => $residual_perc,
                'capitalized_cost' => $capitalized_cost,
                'money_factor' => $money_factor,
                'interest_rate' => $interest_rate,
                'mileage_overage' => $mileage_overage,
                'disposition_fee' => $disposition_fee,
                'end_date' => $end_date
            ];

            // Add to results array
            $results[] = $result;

            // Create a unique identifier for each combination of year, make, model, trim
            $identifier = $year . '|' . $make . '|' . $model . '|' . $trim;

            // Add to uniqueResults array if not already present
            if (!isset($uniqueResults[$identifier])) {
                $uniqueResults[$identifier] = [
                    'year' => $year,
                    'make' => $make,
                    'model' => $model,
                    'trim' => $trim
                ];
            }
        }

        // Convert the associative array to an indexed array for export
        $exportData = array_values($uniqueResults);

        // Export unique results to CSV
        $this->exportToCsv($exportData);

        // Print all results
        echo "<pre>";
        print_r($results);
        echo "</pre>";
    }

    private function getHeaders()
    {
        return [
            'Host: www.buyatoyota.com',
            'Cookie: TOYOTANATIONAL_ENSIGHTEN_PRIVACY_MODAL_VIEWED=1; AMCVS_8F8B67C25245B30D0A490D4C@AdobeOrg=1; ...',
            'Cache-Control: max-age=0',
            'Sec-CH-UA: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "Windows"',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-User: ?1',
            'Sec-Fetch-Dest: document',
            'Accept-Language: en-US,en;q=0.9'
        ];
    }

    private function curlRequest($url, $headers)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);
        return $response;
    }

    private function getAllOfferLinks($html)
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $query = "//a[contains(@href, '/greaterny/offer-detail/?offerid=')]";
        $nodes = $xpath->query($query);

        $links = [];
        $seen_links = []; // Use an associative array to track seen links

        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');
            if (!isset($seen_links[$href])) { // Check if the link is already seen
                $links[] = $href;
                $seen_links[$href] = true; // Mark the link as seen
            }
        }

        return $links;
    }

    private function ensureAbsoluteUrl($url, $base_url)
    {
        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 ? $url : $base_url . $url;
    }

    private function extractOfferDetails($html)
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        // Extract the main offer title
        $titleNode = $xpath->query("//h1[@class='fs67XEFk']");
        $title = $titleNode->length > 0 ? trim($titleNode->item(0)->nodeValue) : 'Not found';

        // Extract lease details (assuming this is the correct div from your previous context)
        $leaseDetailsNode = $xpath->query("//div[@class='I5KoZl70 offer-dt-details']");
        $leaseDetails = $leaseDetailsNode->length > 0 ? trim($leaseDetailsNode->item(0)->nodeValue) : 'Not found';

        // Extract terms from the div identified in your description
        $termsNode = $xpath->query("//div[@class='container M6ODr8_z']/div[@class='Tf18Bjvu']");
        $terms = $termsNode->length > 0 ? trim($termsNode->item(0)->nodeValue) : 'Not found';

        // Extract disclaimer information
        $disclaimerNode = $xpath->query("//div[contains(@class, 'disclaimer-color-grey')]");
        $disclaimer = $disclaimerNode->length > 0 ? trim($disclaimerNode->item(0)->nodeValue) : 'Not found';

        // Assemble all extracted data into an array
        $details = [
            'title' => $title,
            'lease_info' => $leaseDetails,
            'terms' => $terms,
            'disclaimer' => $disclaimer
        ];

        return $details;
    }

    private function extractModel($title)
    {
        preg_match('/(\d{4} [\w\s]+) Lease Offer/i', $title, $matches);
        return $matches[1] ?? 'Not found';
    }

    private function extractTrim($terms)
    {
        preg_match('/lease a new (\d{4} .*?) for/i', strtolower($terms), $matches);
        return $matches[1] ?? 'Not found';
    }

    private function extractMsrp($disclaimer)
    {
        preg_match('/total srp of \$([0-9,]+)/i', $disclaimer, $matches);
        return isset($matches[1]) ? intval(str_replace(',', '', $matches[1])) : 0;
    }

    private function extractMonthlyPayment($leaseInfo)
    {
        preg_match('/\$([0-9]+)\/ mo/i', $leaseInfo, $matches);
        return isset($matches[1]) ? intval($matches[1]) : 0;
    }

    private function extractTerm($leaseInfo)
    {
        preg_match('/([0-9]+)mos/i', $leaseInfo, $matches);
        return isset($matches[1]) ? intval($matches[1]) : 0;
    }

    private function extractDueAtSigning($leaseInfo)
    {
        preg_match('/\$([0-9,]+)due at signing/i', $leaseInfo, $matches);
        return isset($matches[1]) ? intval(str_replace(',', '', $matches[1])) : 0;
    }

    private function extractResidualValue($disclaimer)
    {
        preg_match('/lease end purchase amount of \$([0-9,]+)/i', $disclaimer, $matches);
        return isset($matches[1]) ? intval(str_replace(',', '', $matches[1])) : 0;
    }

    private function extractCapitalizedCost($disclaimer)
    {
        preg_match('/net capitalized cost of \$([0-9,]+)/i', $disclaimer, $matches);
        return isset($matches[1]) ? intval(str_replace(',', '', $matches[1])) : 0;
    }

    private function extractEndDate($disclaimer)
    {
        preg_match('/expires (\d{2}-\d{2}-\d{4})/i', $disclaimer, $matches);
        return isset($matches[1]) ? $matches[1] : 'Not found';
    }

    private function calculateMonthlyPaymentZero($monthly_payment, $due_at_signing, $term)
    {
        return round($monthly_payment + (($due_at_signing - $monthly_payment) / $term), 2);
    }

    private function calculateResidualPercentage($residual_value, $msrp)
    {
        return round(($residual_value / $msrp) * 100);
    }

    private function calculateMoneyFactor($monthly_payment, $capitalized_cost, $residual_value, $term)
    {
        return ($monthly_payment - (($capitalized_cost - $residual_value) / $term)) / ($capitalized_cost + $residual_value);
    }

    private function exportToCsv($results)
    {
        $filename = "toyota_offers.csv";
        $file = fopen($filename, "w");

        // Header row
        fputcsv($file, array_keys($results[0]));

        // Data rows
        foreach ($results as $row) {
            fputcsv($file, $row);
        }

        fclose($file);

        echo "Data exported to $filename";
    }
}



