<?php

namespace App\Http\Controllers;

use App\Entities\Product;
use App\Entities\Product\Asin;
use App\Helpers\Session;
use App\Repositories\Category\LinkRepository;
use App\Repositories\ProductRepository;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use League\Csv\Writer;
use Symfony\Component\DomCrawler\Crawler;

class FrontController extends Controller
{
    public function index(ProductRepository $productRepository)
    {
        \Carbon\Carbon::setLocale('pl');
        $latestProducts = $productRepository->orderBy('created_at', 'DESC')->get()->take(5);
        $logs = file_get_contents(storage_path('logs/' . 'lumen.log'));

        return view('index', [
            'latestProducts' => $latestProducts,
            'logs' => $logs,
            'asinCount' => Asin::count(),
            'productsCount' => Product::count()
        ]);
    }
    /**
     * Create a new controller instance.
     *
     * @param Request $request
     * @param LinkRepository $linkRepository
     * @return RedirectResponse
     */
    public function getSubcategoriesLinks(Request $request, LinkRepository $linkRepository): RedirectResponse
    {
        $categoryLink = $request->input('categoryLink');

        $client = new Client();
        $html = $client->get($categoryLink)->getBody()->getContents();
        $html = $this->purifyHtml($html);

        $crawler = new Crawler($html);

        $counter = 0;
        $crawler->filter('a')->each(function ($node) use ($linkRepository, $client, &$counter) {
            $categoryUrl = $node->attr('href');

            $linkRepository->firstOrCreate([
                'url' => $categoryUrl,
                'current_page' => 1,
                'completed' => false
            ]);

            $counter++;
        });

        Session::getInstance()->put('message', [
            'type' => 'success',
            'text' => 'Pomyślnie dodano linki podkategorii do bazy! Ilość: ' . $counter
        ]);

        return redirect()->route('home');
    }

    /**
     * @param $html
     * @return mixed|string
     */
    private function purifyHtml($html)
    {
        $html = str_after($html, '<ul class="a-unordered-list a-nostyle a-vertical s-ref-indent-one">');
        $html = str_before($html, '</div>');
        $html = str_replace('<div aria-live="polite" class="a-row a-expander-container a-expander-extend-container">', '', $html);

        return $html;
    }

    public function exportToCsv(ProductRepository $productRepository)
    {
        $allProducts = $productRepository->all();
        $data = [];

        foreach ($allProducts as $product) {
            $details = json_decode($product->details);

            $data[] = [
                $product->title ?? '', $product->price ?? '', strip_tags($product->description) ?? '', $details->isbn_10 ?? '', $details->language ?? '', $details->paperback ?? '', $details->publisher ?? ''
            ];
        }

        $writer = Writer::createFromPath(storage_path('records.csv'), 'w+');
        $writer->setDelimiter('^');
        $writer->insertAll($data);

        return response('done.');
    }
}
