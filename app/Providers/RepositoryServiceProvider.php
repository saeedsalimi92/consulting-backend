<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Modules\Counselor\Repositories\{CounselorRepositoryInterface,CounselorRepository,SpecialtyRepositoryInterface,SpecialtyRepository,CommentRepositoryInterface,CommentRepository};
class RepositoryServiceProvider extends ServiceProvider {
 public function register(): void {
  $this->app->bind(CounselorRepositoryInterface::class,CounselorRepository::class);
  $this->app->bind(SpecialtyRepositoryInterface::class,SpecialtyRepository::class);
  $this->app->bind(CommentRepositoryInterface::class,CommentRepository::class);
 }
}
