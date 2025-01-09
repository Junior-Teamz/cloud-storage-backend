<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsTag;
use App\Models\Tags;
use Illuminate\Support\Str;
use App\Services\CheckAdminService;
use App\Services\GenerateURLService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class NewsController extends Controller
{
    protected $checkAdminService;
    protected $generateImageURL;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService, GenerateURLService $generateURLService)
    {
        $this->checkAdminService = $checkAdminService;
        $this->generateImageURL = $generateURLService;
    }

    /**
     * Get all news for admin.
     *
     * This method retrieves all news articles, including their creator and associated tags,
     * with optional filtering by title and status. It returns a paginated list of news articles
     * with 10 items per page.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional query parameters for filtering.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of news articles or an error message.
     */
    public function getAllNews(Request $request)
    {
        // Cek apakah user adalah admin
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Ambil parameter query dari request
            $titleNews = $request->query('title');
            $status = $request->query('status');

            // Query dasar untuk mengambil berita dengan relasi creator dan tags
            $query = News::with(['creator:id,name,email,roles,photo_profile_url', 'creator.instances:id,name,address', 'tags:id,name']);

            // Tambahkan filter berdasarkan nama creator jika ada
            if (!empty($titleNews)) {
                $query->whereHas('title', function ($q) use ($titleNews) {
                    $q->where('name', 'like', '%' . $titleNews . '%');
                });
            }

            // Tambahkan filter berdasarkan status jika ada
            if (!empty($status)) {
                $query->where('status', $status);
            }

            // Lakukan pagination 10 item per halaman
            $news = $query->paginate(10);

            if ($news->isEmpty()) {
                return response()->json([
                    'message' => 'No news found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'All news successfully retrieved',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error while getting all news: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while getting all news.'
            ], 500);
        }
    }

    /**
     * Get all published news for public access.
     *
     * This method retrieves all published news articles, including their creator's name, instance, and associated tags.
     * It allows filtering by title and returns a paginated list of news articles with 10 items per page.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional query parameters for filtering.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of news articles or an empty array if no news is found.
     */
    public function getAllNewsForPublic(Request $request)
    {
        try {
            // Ambil parameter query dari request
            $titleNews = $request->query('title');

            // Ambil semua data berita beserta nama pembuat dan tag-nya, dengan pagination 10 item per halaman
            $queryNews = News::with(['creator:id,name,photo_profile_url', 'creator.instances:name,address', 'tags:id,name']);

            if (!empty($titleNews)) {
                $queryNews->whereHas('title', function ($q) use ($titleNews) {
                    $q->where('name', 'like', '%' . $titleNews . '%');
                });
            }

            $news = $queryNews->where('status', 'published')->paginate(10);

            if ($news->isEmpty()) {
                return response()->json([
                    'message' => 'No news found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'News successfully retrieved',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while getting news: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while getting news.'
            ], 500);
        }
    }

    /**
     * Get a published news article by UUID.
     *
     * This method retrieves a published news article from the database based on the provided UUID.
     * It includes the creator's name, instance, and associated tags in the response. If the news
     * article is not found, a 200 OK response is returned with an empty data array and a message
     * indicating that the news was not found.
     * 
     * If the request originates from a frontend URL listed in the `frontend.url` configuration,
     * the viewer count for the news article is incremented.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request.
     * @param  string  $id The UUID of the news article to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the news article or an error message.
     */
    public function getNewsById(Request $request, $id)
    {
        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,name,photo_profile_url',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:name,address',
                'tags:id,name'  // Ambil id dan name dari relasi tags (NewsTag)
            ])
                ->where('status', 'published')
                ->where('id', $id)->first();

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
            }

            $origin = $request->header('Origin', '');
            // jika permintaan mengambil informasi berita dari frontend, tambahkan count viewers
            if (in_array($origin, config('frontend.url'))) {
                $news->increment('viewer');
            }

            return response()->json([
                'message' => 'News successfully retrieved.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting news by ID: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while getting the news.'
            ], 500);
        }
    }

    // public function addNewsViewers(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'news_id' => 'required|string|exists:news,id'
    //     ]);

    //     if($validator->fails()){
    //         return response()->json([
    //             'errors' => $validator->errors()
    //         ]);
    //     }
    //     try {
    //         $newsIdRequest = $request->news_id;
    //         $news = News::where('id', $newsIdRequest)->first();

    //         DB::beginTransaction();
    //         $news->increment('viewer');
    //         $news->save();
    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Successfully adding viewers count to the news.'
    //         ], 200);

    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         Log::error('Error occurred while adding viewers count to the news: ' . $e->getMessage(), [
    //             'news_id' => $newsIdRequest,
    //             'trace' => $e->getTrace()
    //         ]);
    //         return response()->json([
    //             'An error occurred while adding viewers count to the news.'
    //         ], 500);
    //     }
    // }

    /**
     * Get a published news article by slug.
     *
     * This method retrieves a published news article from the database based on the provided slug.
     * It includes the creator's name, instance, and associated tags in the response. If the news
     * article is not found, a 200 OK response is returned with an empty data array and a message
     * indicating that the news was not found.
     * 
     * If the request originates from a frontend URL listed in the `frontend.url` configuration,
     * the viewer count for the news article is incremented.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request.
     * @param  string  $slug The slug of the news article to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the news article or an error message.
     */
    public function getNewsBySlug(Request $request, $slug)
    {
        if (!is_string($slug)) {
            return response()->json([
                'errors' => 'Parameter must be a slug of news.'
            ], 400);
        }

        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,name,photo_profile_url',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:name,address',
                'tags:id,name'  // Ambil id dan name dari relasi tags (NewsTag)
            ])
                ->where('status', 'published')
                ->where('slug', $slug)->first();

            // Jika berita tidak ditemukan, kembalikan response 200
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
            }

            $origin = $request->header('Origin', '');
            // jika permintaan mengambil informasi berita dari frontend, tambahkan count viewers
            if (in_array($origin, config('frontend.url'))) {
                $news->increment('viewer');
            }

            return response()->json([
                'message' => 'News successfully retrieved.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting news by ID: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while getting the news.'
            ], 500);
        }
    }

    /**
     * Get news details for admin.
     *
     * This method retrieves a news article by its UUID, including details about its creator, instance, and associated tags.
     * It returns a JSON response containing the news details or a 200 OK response with an empty data array and a message
     * indicating that the news was not found if the news article does not exist.
     *
     * @param string $newsId The UUID of the news article to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the news details or an error message.
     */
    public function getNewsDetailForAdmin($newsId)
    {
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Ambil berita berdasarkan ID beserta nama pembuat dan tag-nya
            $news = News::with([
                'creator:id,name,email,roles,photo_profile_url',  // Ambil id dan name dari relasi creator (User)
                'creator.instances:id,name,address',
                'tags:id,name'  // Ambil id dan name dari relasi tags (NewsTag)
            ])->where('id', $newsId)->first();

            // Jika berita tidak ditemukan, kembalikan response 404
            if (!$news) {
                return response()->json([
                    'message' => 'News not found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'News detail successfully retrieved.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting news by ID: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while getting the news.'
            ], 500);
        }
    }

    /**
     * Create a new news article.
     *
     * This method handles the creation of a new news article. It validates the incoming request, ensuring that
     * the required fields are present and meet the specified criteria. It also handles the upload and storage
     * of the news thumbnail, either as a file or a URL. The method then creates a new News record in the database,
     * associates it with the provided tags, and returns a JSON response containing the newly created news article.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the news article data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function createNews(Request $request)
    {
        // Cek apakah user yang login adalah admin
        $userLogin = Auth::user();
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.create');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'content' => 'required|string',
            'status' => 'nullable|in:published,archived',
            'thumbnail' => 'required|file|max:2048|mimes:jpeg,jpg,png',  // Thumbnail wajib, maksimal 2MB
            'news_tag_ids' => 'required|array',
        ], [
            'title.max' => 'News title cannot exceed more than 100 characters.',
            'status.in' => 'Status must be either published or archived.',
            'news_tag_ids.array' => 'news_tag_id must be an array of news tags.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $newsTagIdRequest = $request->news_tag_ids;

        try {
            $tags = Tags::whereIn('id', $newsTagIdRequest)->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundNewsTagIds = $tags->pluck('id')->toArray();
            $foundNewsTagIdsToCheck = $tags->pluck('id')->toArray();
            $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIdsToCheck);

            if (!empty($notFoundTagIds)) {
                Log::info('Non-existence news tag found: ' . implode(',', $notFoundTagIds));

                return response()->json([
                    'errors' => 'Some news tags were not found.',
                    'missing_news_tag_ids' => $notFoundTagIds,
                ], 404);
            }

            // Cek file atau string untuk thumbnail
            if ($request->hasFile('thumbnail') || is_string($request->thumbnail)) {
                if ($request->hasFile('thumbnail')) {

                    // Thumbnail adalah file, validasi tipe gambar dan ukurannya
                    $file = $request->file('thumbnail');

                    $allowedMimeTypes = ['image/jpeg', 'image/png'];
                    if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid image (jpeg or png).'
                        ], 422);
                    }

                    if ($file->getSize() > 5 * 1024 * 1024) {
                        return response()->json([
                            'errors' => 'The thumbnail must not be larger than 5MB.'
                        ], 422);
                    }

                    // Nama folder untuk menyimpan thumbnail
                    $thumbnailDirectory = 'news_thumbnail';

                    // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
                    if (!Storage::disk('public')->exists($thumbnailDirectory)) {
                        Storage::disk('public')->makeDirectory($thumbnailDirectory);
                    }

                    // Simpan file thumbnail ke storage/app/public/news_thumbnail
                    $thumbnailPath = $file->store($thumbnailDirectory, 'public');

                    // Buat URL publik untuk thumbnail
                    $thumbnailUrl = Storage::disk('public')->url($thumbnailPath);
                    
                } else if (is_string($request->thumbnail)) {
                    // Periksa apakah thumbnail adalah URL yang valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    // Jika thumbnail adalah URL yang valid, simpan string URL tersebut
                    $thumbnailUrl = $request->thumbnail;
                }
            } else {
                return response()->json([
                    'errors' => 'Thumbnail must be a file or string.'
                ], 422);
            }

            // Siapkan slug dari judul berita, tambahkan tanggal dengan timezone Jakarta
            $date = Carbon::now('Asia/Jakarta')->format('Y-m-d');

            // Bersihkan tag HTML dari judul berita menggunakan strip_tags
            $cleanedTitle = strip_tags($request->title);

            // Buat slug dari judul yang sudah dibersihkan
            $slug = Str::slug($cleanedTitle) . '-' . $date;

            DB::beginTransaction();

            // Simpan berita ke database
            $news = News::create([
                'created_by' => $userLogin->id,
                'title' => $request->title,
                'thumbnail_path' => $thumbnailPath ?? null,
                'thumbnail_url' => $thumbnailUrl,
                'slug' => $slug,
                'content' => $request->content,
                'viewer' => 0,  // viewer dimulai dari 0
                'status' => $request->status ?? 'archived'  // default status adalah archived
            ]);

            // Hubungkan berita dengan tag
            $news->tags()->sync($foundNewsTagIds);

            DB::commit();

            $news->load(['creator:id,name,email,roles,photo_profile_url', 'creator.instances:id,name,address', 'tags:id,name']);

            return response()->json([
                'message' => 'News successfully created.',
                'news' => $news
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error while creating news: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'Internal error occurred, please try again later.'
            ], 500);
        }
    }

    /**
     * Update an existing news article.
     *
     * This method handles the update of an existing news article. It validates the incoming request, ensuring that
     * the provided fields meet the specified criteria. It also handles the update of the news thumbnail, which can
     * be either a file upload or a URL. If a new thumbnail file is uploaded, the old thumbnail is deleted from storage.
     * The method then updates the News record in the database with the new data and returns a JSON response containing
     * the updated news article.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated news article data.
     * @param string $id The UUID of the news article to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function updateNews(Request $request, $id)
    {
        // Cek apakah user adalah admin
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.update');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:100',
            'content' => 'nullable|string',
            'status' => 'nullable|in:published,archived',
            'thumbnail' => 'nullable|file|max:2048|mimes:jpeg,jpg,png', // Thumbnail opsional
            'news_tag_id' => 'nullable|array', // Tag opsional, tapi harus valid
        ], [
            'title.max' => 'News title cannot exceed more than 100 characters.',
            'status.in' => 'Status must be either published or archived.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('news_tag_id')) {
            $newsTagIdRequest = $request->news_tag_ids;
        } else {
            $newsTagIdRequest = null;
        }

        try {
            if (!is_null($newsTagIdRequest)) {
                $tags = Tags::whereIn('id', $newsTagIdRequest)->get();

                // Bandingkan ID yang ditemukan dengan yang diminta
                $foundNewsTagIds = $tags->pluck('id')->toArray();
                $foundNewsTagIdsToCheck = $tags->pluck('id')->toArray();
                $notFoundTagIds = array_diff($newsTagIdRequest, $foundNewsTagIdsToCheck);

                if (!empty($notFoundTagIds)) {
                    Log::info('Non-existence news tag found: ' . implode(',', $notFoundTagIds));

                    return response()->json([
                        'errors' => 'Some news tags were not found.',
                        'missing_news_tag_ids' => $notFoundTagIds,
                    ], 404);
                }
            }

            // Ambil berita berdasarkan ID
            $news = News::where('id', $id)->first();

            if (!$news) {
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            // Periksa apakah title, content, status, atau thumbnail diberikan input, jika tidak gunakan nilai lama
            $news->title = $request->input('title', $news->title);
            $news->content = $request->input('content', $news->content);
            $news->status = $request->input('status', $news->status);

            // Periksa apakah thumbnail ingin diubah
            if ($request->has('thumbnail')) {
                if ($request->hasFile('thumbnail')) {
                    // Thumbnail adalah file, periksa apakah valid
                    $file = $request->file('thumbnail');

                    // Periksa tipe file (jpeg/png)
                    $allowedMimeTypes = ['image/jpeg', 'image/png'];
                    if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid image (jpeg or png).'
                        ], 422);
                    }

                    // Periksa ukuran file (maksimal 5 MB)
                    if ($file->getSize() > 5 * 1024 * 1024) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must not be larger than 5MB.'
                        ], 422);
                    }

                    // Nama folder untuk menyimpan thumbnail
                    $thumbnailDirectory = 'news_thumbnail';

                    // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
                    if (!Storage::disk('public')->exists($thumbnailDirectory)) {
                        Storage::disk('public')->makeDirectory($thumbnailDirectory);
                    }

                    // Cek apakah ada thumbnail lama dan hapus jika ada
                    if ($news->thumbnail_path && Storage::disk('public')->exists($news->thumbnail_path)) {
                        Storage::disk('public')->delete($news->thumbnail_path);
                    }

                    // Simpan file thumbnail ke storage/app/public/news_thumbnail
                    $thumbnailPath = $file->store($thumbnailDirectory, 'public');

                    // Buat URL publik untuk thumbnail
                    $thumbnailUrl = Storage::disk('public')->url($thumbnailPath);

                    // Simpan path dan URL thumbnail ke dalam model
                    $news->thumbnail_path = $thumbnailPath;
                    $news->thumbnail_url = $thumbnailUrl;
                } elseif (is_string($request->thumbnail)) {
                    // Thumbnail adalah string, cek apakah ini URL valid
                    if (!filter_var($request->thumbnail, FILTER_VALIDATE_URL)) {
                        DB::rollBack();
                        return response()->json([
                            'errors' => 'The thumbnail must be a valid URL.'
                        ], 422);
                    }

                    // Jika sebelumnya ada thumbnail file, hapus file lama
                    if ($news->thumbnail_path && Storage::disk('public')->exists($news->thumbnail_path)) {
                        Storage::disk('public')->delete($news->thumbnail_path);
                        $news->thumbnail_path = null; // Setel path ke null
                    }

                    // Simpan URL thumbnail baru
                    $news->thumbnail_url = $request->thumbnail;
                }
            }

            // Jika ada tag yang di-update, sinkronkan tag
            if (!is_null($newsTagIdRequest)) {
                $news->tags()->sync($foundNewsTagIds);
            }

            // Simpan perubahan
            $news->save();

            DB::commit();

            $news->load(['creator:id,name,email,roles,photo_profile_url', 'creator.instances:id,name,address', 'tags:id,name']);

            return response()->json([
                'message' => 'News updated successfully.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occurred while updating news: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while updating the news.'
            ], 500);
        }
    }

    /**
     * Delete a news article.
     *
     * This method handles the deletion of a news article. It first checks if the authenticated user
     * is an admin. If not, a 403 Forbidden response is returned. If the news article exists, it is
     * deleted from the database, along with its associated tags. The thumbnail, if it's a file
     * stored in the storage, is also deleted.
     * 
     * Requires admin authentication.
     * 
     * **Caution:** Deleting a news article is a destructive action and cannot be undone. Ensure that the
     * news article is no longer needed before proceeding with the deletion.
     *
     * @param string $id The UUID of the news article to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function deleteNews($id)
    {
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.delete');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        try {
            $news = News::where('id', $id)->first();
            if (!$news) {
                Log::warning('Attempt to delete non-existence news with news ID: ' . $id);
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            DB::table('news_has_tags')->where('news_id', $news->id)->delete();

            // Cek jika thumbnail adalah path file di storage (tidak berupa URL)
            if ($news->thumbnail && !filter_var($news->thumbnail, FILTER_VALIDATE_URL)) {
                // Pastikan file thumbnail masih ada di storage sebelum menghapusnya
                if (Storage::disk('public')->exists($news->thumbnail)) {
                    Storage::disk('public')->delete($news->thumbnail);
                    Log::info('Thumbnail deleted: ' . $news->thumbnail);
                } else {
                    Log::warning('Thumbnail file not found in storage: ' . $news->thumbnail);
                }
            }

            $news->delete();

            DB::commit();

            return response()->json([
                'message' => 'News deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occured while deleting news: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while deleting the news.'
            ], 500);
        }
    }

    /**
     * Change the status of a news article.
     *
     * This method handles the change of status for a news article. It validates the incoming request,
     * ensuring that the provided status is either 'published' or 'archived'. If the validation passes,
     * it updates the news article's status in the database and returns a JSON response containing
     * the updated news article.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the new status.
     * @param string $newsId The UUID of the news article to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function changeStatus(Request $request, $newsId)
    {
        $checkAdmin = $this->checkAdminService->checkAdminWithPermissionOrSuperadmin('news.status.update');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:published,archived',
        ], [
            'status.in' => 'Status must be either published or archived.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $news = News::where('id', $newsId)->first()->first();

            if (!$news) {
                Log::warning('Attempt to change status of non-existence news with news ID: ' . $newsId);
                return response()->json([
                    'errors' => 'News not found.'
                ], 404);
            }

            DB::beginTransaction();

            $news->status = $request->status;

            $news->save();

            DB::commit();

            $news->load(['creator:id,name,email,roles,photo_profile_url', 'creator.instances:id,name,address', 'tags:id,name']);

            return response()->json([
                'message' => 'News status changed successfully.',
                'data' => $news
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occured while changing news status: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while changing the news status.'
            ], 500);
        }
    }


    // ENDPOINT UNTUK TESTING IMAGE
    public function storeImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|max:5120|mimes:img,png,svg,jpg,webp'
        ]);

        if($validator->fails()){
            return response()->json([
                'errors' => $validator->errors()
            ]);
        }

        try {
            $file = $request->file('image');

            $folderImageStore = 'image_testing';

            if (!Storage::disk('public')->exists($folderImageStore)) {
                Storage::disk('public')->makeDirectory($folderImageStore);
            }

            $filePath = $file->store($folderImageStore, 'public');

            $url = Storage::disk('public')->url($filePath);

            return response()->json([
                'message' => 'Image stored successfully',
                'data' => [
                    'image_url' => $url
                ]
            ], 200);

        } catch (Exception $e){
            Log::error('Error occured while storing image testing: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'Internal server error!, please check log!'
            ], 500);
        }
    }
}
