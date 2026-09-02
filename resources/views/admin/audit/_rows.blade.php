{{-- Audit table + pagination.

     Split out of index.blade.php so the live filters can swap just this over
     fetch -- the shared "_rows partial" pattern the other list pages use (see
     the AJAX partial note in REMEDI.md). Edit this file, not a copy inside
     index.blade.php. --}}
  {{-- Table --}}
  {{-- overflow:hidden on the card would CLIP this table on a phone instead of
       scrolling it, leaving Date & Time unreachable -- hence .table-scroll
       inside the rounded card rather than on it. min-width keeps the six
       columns legible instead of crushing them. --}}
  <div style="border:0.5px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fff;">
    <div class="table-scroll">
    <table style="width:100%; min-width:760px; border-collapse:collapse; font-size:13px; table-layout:fixed;">
      <thead>
        <tr style="background:#f9fafb;">
          <th style="width:42px;  padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">#</th>
          <th style="width:120px; padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">Username</th>
          <th style="width:90px;  padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">Role</th>
          <th style="width:100px; padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">Action</th>
          <th style="           padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">Details</th>
          <th style="width:155px; padding:10px 14px; text-align:left; font-size:12px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:0.5px solid #e5e7eb;">Date &amp; Time</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($logs as $log)
        <tr style="border-bottom:0.5px solid #e5e7eb;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
          <td style="padding:11px 14px; color:#9ca3af; font-size:12px;">
            {{ $loop->iteration + ($logs->currentPage() - 1) * $logs->perPage() }}
          </td>

          {{-- Username with avatar --}}
          <td style="padding:11px 14px;">
            <span style="display:inline-flex; align-items:center; gap:7px;">
              @php
                $isAdmin = strtolower($log->role) === 'admin';
                $initials = collect(explode(' ', $log->username))->map(fn($w) => strtoupper($w[0]))->take(2)->join('');
              @endphp
              <span style="width:26px; height:26px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:500; flex-shrink:0;
                           background:{{ $isAdmin ? '#E6F1FB' : '#EAF3DE' }};
                           color:{{ $isAdmin ? '#185FA5' : '#3B6D11' }};">
                {{ $initials }}
              </span>
              {{ $log->username }}
            </span>
          </td>

          {{-- Role badge --}}
          <td style="padding:11px 14px;">
            @if(strtolower($log->role) === 'admin')
              <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:500; padding:3px 8px; border-radius:20px; background:#E6F1FB; color:#0C447C;">
                <i class="ti ti-shield" style="font-size:11px;"></i> Admin
              </span>
            @else
              <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:500; padding:3px 8px; border-radius:20px; background:#EAF3DE; color:#27500A;">
                <i class="ti ti-user" style="font-size:11px;"></i> Staff
              </span>
            @endif
          </td>

          {{-- Action badge --}}
          <td style="padding:11px 14px;">
            @php
              $actionStyles = [
                'login'  => ['bg'=>'#EAF3DE','color'=>'#27500A','icon'=>'ti-login'],
                'logout' => ['bg'=>'#F1EFE8','color'=>'#5F5E5A','icon'=>'ti-logout'],
                'viewed' => ['bg'=>'#EEEDFE','color'=>'#3C3489','icon'=>'ti-eye'],
                'create' => ['bg'=>'#E1F5EE','color'=>'#085041','icon'=>'ti-plus'],
                'update' => ['bg'=>'#FAEEDA','color'=>'#633806','icon'=>'ti-edit'],
                'delete' => ['bg'=>'#FCEBEB','color'=>'#791F1F','icon'=>'ti-trash'],
              ];
              $key = strtolower($log->action);
              $style = $actionStyles[$key] ?? ['bg'=>'#F1EFE8','color'=>'#5F5E5A','icon'=>'ti-activity'];
            @endphp
            <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:500; padding:3px 8px; border-radius:20px;
                         background:{{ $style['bg'] }}; color:{{ $style['color'] }};">
              <i class="ti {{ $style['icon'] }}" style="font-size:11px;"></i>
              {{ ucfirst($log->action) }}
            </span>
          </td>

          {{-- Details --}}
          <td style="padding:11px 14px; color:#6b7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->details }}">
            {{ $log->details }}
          </td>

          {{-- Date & Time --}}
          <td style="padding:11px 14px; font-size:12px; color:#6b7280; font-variant-numeric:tabular-nums;">
            {{ \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i:s') }}
          </td>
        </tr>
        @empty
        <tr>
          <td colspan="6" style="padding:40px; text-align:center; color:#9ca3af; font-size:14px;">
            <i class="ti ti-search" style="font-size:24px; display:block; margin-bottom:8px;"></i>
            No audit logs found.
          </td>
        </tr>
        @endforelse
      </tbody>
    </table>
    </div>

    {{-- Footer --}}
    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-top:0.5px solid #e5e7eb; font-size:12px; color:#6b7280;">
      <span>
        Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} entries
      </span>
      <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
    </div>
  </div>

  {{-- The same pager every other list renders: $paginator->links() resolving
       to vendor/pagination/custom (registered as the default view in
       AppServiceProvider), styled by .pagination in layouts/app.

       This page used to draw its own -- chevron squares and a blue #185FA5
       current page, none of it shared with anything else -- so the audit trail
       was the one list whose page numbers looked like a different application.
       The AJAX handler in index.blade.php delegates on any <a href> inside the
       wrapper, so it keeps paginating in place either way. --}}
  <div class="audit-pager">{{ $logs->links() }}</div>
