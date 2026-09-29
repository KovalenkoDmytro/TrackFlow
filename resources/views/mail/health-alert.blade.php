@if ($recovered)
All health checks are passing again.
@else
The following problems were detected:

@foreach ($problems as $problem)
- {{ $problem }}
@endforeach

Run `php artisan health:check --no-mail` on the server to re-check.
@endif
