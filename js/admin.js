/**
 * Main Admin Javascript for Optimal State
 * Version 1.1.7
 */
jQuery(document).ready(function($) {
  'use strict';
  const POLL_INTERVAL_FAST = 1000;
  const POLL_INTERVAL_NORMAL = 2000;
  const POLL_INTERVAL_SLOW = 3000;
  const SELECTORS = {
    restoreBtn: '.restore-backup',
    restoreFileBtn: '#optistate-restore-file-btn',
    deleteBtn: '.delete-backup',
    globalButtons: '.restore-backup, #optistate-restore-file-btn, .delete-backup'
  };
  const {
    __,
    sprintf
  } = wp.i18n;
  let isRestoreInProgress = false;

  function acquireRestoreLock() {
    if(isRestoreInProgress) {
      return false;
    }
    isRestoreInProgress = true;
    return true;
  }

  function releaseRestoreLock() {
    isRestoreInProgress = false;
  }
  const ESCAPE_MAP = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };

  function esc_attr(text) {
    if(text === undefined || text === null) return '';
    return String(text).replace(/[&<>"']/g, s => ESCAPE_MAP[s]);
  }

  function esc_html(str) {
    if(str === undefined || str === null) return '';
    return String(str).replace(/[&<>"']/g, s => ESCAPE_MAP[s]);
  }

  function restoreButtonToDefault($button) {
    if(!$button || !$button.length) return;
    releaseRestoreLock();
    $button.data('retry-count', 0);
    $button.prop('disabled', false);
    if($button.is(SELECTORS.restoreFileBtn)) {
      $button.html('<span class="dashicons dashicons-upload"></span> ' + __('Restore from File', 'optistate'));
      resetUploadUI();
    } else if($button.is(SELECTORS.restoreBtn)) {
      $button.html('<span class="dashicons dashicons-backup"></span> ' + __('Restore', 'optistate'));
    }
  }

  function pollAction(action, data, onResponse, interval) {
    $.ajax({
      url: optistate_BackupMgr.ajax_url,
      type: 'POST',
      data: Object.assign({
        action: action,
        nonce: optistate_BackupMgr.nonce
      }, data),
      timeout: 90000,
      success: function(response) {
        if(onResponse(response)) {
          setTimeout(() => pollAction(action, data, onResponse, interval), interval);
        }
      },
      error: function(xhr) {
        onResponse({
          success: false,
          data: {
            message: xhr.responseJSON?.data?.message || __('Connection error.', 'optistate')
          }
        });
      }
    });
  }

  function pollBackupStatus(transient_key, $button) {
    if(!$button || !$button.length) {
      showToast(__("Backup UI reference lost.", "optistate"), "error");
      return;
    }
    pollAction('optistate_check_backup_status', {
      transient_key: transient_key
    }, (response) => {
      if(response && response.success && response.data) {
        const data = response.data;
        if(data.status === 'running') {
          $button.html('<span class="spinner is-active" style="float:none;"></span> ' + __('<strong>BACKING UP ....</strong>', 'optistate'));
          $(SELECTORS.globalButtons).prop('disabled', true); 
          return true;
        } else if(data.status === 'compressing') {
          $button.html('<span class="spinner is-active" style="float:none;"></span> ' + __('<strong>COMPRESSING ....</strong>', 'optistate'));
          $(SELECTORS.globalButtons).prop('disabled', true);
          return true;
        } else if(data.status === 'done') {
          $button.html('<span class="spinner is-active" style="float:none;"></span> ' + __('<strong>COMPRESSING ....</strong>', 'optistate'));
          setTimeout(function() {
            showToast(data.message || __('Backup complete!', 'optistate'), 'success');
            $backupSpinner.hide();
            $button.prop('disabled', true).html('<span class="dashicons dashicons-yes-alt"></span> ' + '<strong>' + __('BACKUP COMPLETE!', 'optistate') + '</strong>');
            $(SELECTORS.globalButtons).prop('disabled', false);
            setTimeout(function() {
              $button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + '<strong>' + __('Create Backup Now', 'optistate') + '</strong>');
              $button.data('retry-count', 0);
            }, 5000);
            if(data.backups) {
              updateBackupsList(data.backups);
            }
            loadOptimizationLog();
          }, 1200);
          return false;
        }
      }
      const errorMsg = (response && response.data && response.data.message) ? response.data.message : __('Backup failed during processing.', 'optistate');
      showToast(errorMsg, 'error');
      $button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> ' + '<strong>' + __('Create Backup Now', 'optistate') + '</strong>');
      $button.data('retry-count', 0);
      $backupSpinner.hide();
      $(SELECTORS.globalButtons).prop('disabled', false);
      return false;
    }, POLL_INTERVAL_NORMAL);
  }

  function pollRestoreStatus(master_restore_key, $button) {
    pollAction('optistate_get_restore_status', {
      master_restore_key: master_restore_key
    }, (response) => {
      if(response && response.success && response.data) {
        const data = response.data;
        if(data.status === 'done' || data.final_success_flag === true) {
          if($button && $button.length) {
            $button.html('<span class="dashicons dashicons-yes-alt"></span> ' + __('RESTORE COMPLETE!', 'optistate'));
          }
          releaseRestoreLock();
          showToast((data.message || __('Restore complete!', 'optistate')) + ' ' + __('⏳ Page will reload shortly...', 'optistate'), 'success');
          setTimeout(() => location.reload(), 10000);
          return false;
        } else if(['safety_backup_starting', 'safety_backup_running', 'restore_starting', 'restore_running', 'rollback_starting'].includes(data.status)) {
          let statusMessage = data.message || __('PROCESSING ....', 'optistate');
          if($button && $button.length) {
            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;"></span> ' + statusMessage);
          }
          $createBackupBtn.prop('disabled', true);
          return true;
        } else if(['rollback_done', 'error', 'not_running'].includes(data.status)) {
          releaseRestoreLock();
          const type = data.status === 'rollback_done' ? 'warning' : (data.status === 'not_running' ? 'info' : 'error');
          showToast(data.message || __('Restore finished.', 'optistate'), type);
          if($button && $button.length) {
            restoreButtonToDefault($button);
          }
          $(SELECTORS.globalButtons).prop('disabled', false);
          $createBackupBtn.prop('disabled', false);
          return false;
        }
      }
      releaseRestoreLock();
      showToast(__('Connection error. Please reload the page and check the Activity Log.<br>This may be normal if the restore is successful.', 'optistate'), 'error');
      if($button && $button.length) restoreButtonToDefault($button);
      $(SELECTORS.globalButtons).prop('disabled', false);
      $createBackupBtn.prop('disabled', false);
      return false;
    }, POLL_INTERVAL_SLOW);
  }

  function pollDecompressionStatus(decompression_key, $button) {
    if(!$button || !$button.length) {
      releaseRestoreLock();
      return;
    }
    pollAction('optistate_check_decompression_status', {
      decompression_key: decompression_key
    }, (response) => {
      if(response && response.success && response.data) {
        const data = response.data;
        if(data.status === 'decompressing') {
          $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;"></span> ' + sprintf(__('DECOMPRESSING BACKUP ....')));
          if($button.is(SELECTORS.restoreFileBtn)) {
            $('.optistate-progress-fill').text(__('DECOMPRESSING ....', 'optistate'));
          }
          $createBackupBtn.prop('disabled', true);
          return true;
        } else if(data.status === 'restore_starting') {
          pollRestoreStatus(data.master_restore_key, $button);
          return false;
        } else {
          releaseRestoreLock();
          showToast(data.message || __('Unknown status.', 'optistate'), 'error');
          restoreButtonToDefault($button);
          $createBackupBtn.prop('disabled', false);
          return false;
        }
      }
      releaseRestoreLock();
      const errorMsg = (response && response.data && response.data.message) ? response.data.message : __('Decompression failed.', 'optistate');
      showToast(errorMsg, 'error');
      restoreButtonToDefault($button);
      $createBackupBtn.prop('disabled', false);
      return false;
    }, POLL_INTERVAL_SLOW);
  }

  function formatBytes(bytes, decimals = 2) {
    const numBytes = parseInt(bytes, 10);
    if(isNaN(numBytes) || numBytes < 0) {
      return __('0 Bytes', 'optistate');
    }
    if(numBytes === 0) return __('0 Bytes', 'optistate');
    const k = 1024;
    const dm = Math.max(0, Math.min(decimals, 4));
    const sizes = [
      __('Bytes', 'optistate'),
      __('KB', 'optistate'),
      __('MB', 'optistate'),
      __('GB', 'optistate'),
      __('TB', 'optistate')
    ];
    const i = Math.min(Math.floor(Math.log(numBytes) / Math.log(k)), sizes.length - 1);
    return parseFloat((numBytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
  }

  function parseSizeToBytes(sizeText) {
    if (!sizeText || typeof sizeText !== 'string') {
      return 0;
    }
    const cleanText = sizeText.replace(/,/g, '').replace(/[^\d. A-Za-z]/g, '').trim();
    const match = cleanText.match(/^([\d.]+)\s*([A-Za-z]+)/i);
    if (!match) {
      return 0;
    }
    const value = parseFloat(match[1]);
    const unitLabel = match[2];
    const units = {
      'B': 1, 'Bytes': 1,
      'KB': 1024,
      'MB': 1024 * 1024,
      'GB': 1024 * 1024 * 1024,
      'TB': 1024 * 1024 * 1024 * 1024
    };
    const unitKey = Object.keys(units).find(k => k.toLowerCase() === unitLabel.toLowerCase());
    if (isNaN(value) || !unitKey) {
      return 0;
    }
    const bytes = Math.round(value * units[unitKey]);
    return isNaN(bytes) ? 0 : bytes;
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }
  const $body = $('body');
  const $backupsList = $('#backups-list');
  const $createBackupBtn = $('#create-backup-btn');
  const $backupSpinner = $('#backup-spinner');
  const $autoOptimizeDays = $('#auto_optimize_days');
  const $autoOptimizeTime = $('#auto_optimize_time');
  const $autoBackupOnly = $('#auto_backup_only');
  const $emailNotifications = $('#email_notifications');
  const $maxBackupsSetting = $('#max_backups_setting');
  const $saveAutoOptimizeBtn = $('#save-auto-optimize-btn');
  const $statsLoading = $('#optistate-stats-loading');
  const $statsWrapper = $('#optistate-stats-wrapper');
  const $statsContainer = $('#optistate-stats');
  const $dbSizeValue = $('#optistate-db-size-value');
  const $refreshStatsBtn = $('#optistate-refresh-stats');
  const $healthScoreLoading = $('#optistate-health-score-loading');
  const $healthScoreWrapper = $('#optistate-health-score-wrapper');
  const $refreshHealthScoreBtn = $('#optistate-refresh-health-score');
  const $cleanupItemsContainer = $('#optistate-cleanup-items');
  const $oneClickOptimizeBtn = $('#optistate-one-click');
  const $perfFeaturesContainer = $('#optistate-performance-features-container');
  const $settingsLogContainer = $('#optistate-settings-log');

  function updateBackupsList(backups) {
    if(!Array.isArray(backups)) {
      return;
    }
    $backupsList.empty();
    if(backups.length === 0) {
      const emptyMsg = '<tr><td colspan="4" class="db-backup-empty">' +
        esc_attr(__('No backups found. Create your first backup!', 'optistate')) + '</td></tr>';
      $backupsList.html(emptyMsg);
      return;
    }
    const fragment = document.createDocumentFragment();
    backups.forEach(function(backup) {
      if(!backup.filename || !backup.date || !backup.size) {
        return;
      }
      const verificationStatus = backup.verified ?
        '<span class="db-backup-verified optistate-integrity-info" style="cursor: pointer;" data-status="verified">✓ ' + esc_attr(__('File integrity', 'optistate')) + '</span>' :
        '<span class="db-backup-unverified optistate-integrity-info" style="cursor: pointer;" data-status="unverified">⚠ ' + esc_html(__('File integrity', 'optistate')) + '</span>';
      const row = document.createElement('tr');
      row.setAttribute('data-file', esc_attr(backup.filename));
      if (backup.size_bytes) {
        row.setAttribute('data-bytes', esc_attr(backup.size_bytes));
      }
      row.innerHTML = `
        <td>
          <strong>${esc_html(backup.filename)}</strong>
          <div style="margin-top: 5px; font-size: 12px;">
            ${verificationStatus}
          </div>
        </td>
        <td><span style="white-space: nowrap;">${esc_html(backup.date)}</span></td>
        <td>${esc_html(backup.size)}</td>
        <td>
     <button class="button download-backup" data-file="${esc_attr(backup.filename)}" data-download-url="${esc_attr(backup.download_url)}">
            <span class="dashicons dashicons-download"></span> ${__('Download', 'optistate')}
          </button>
          <button class="button restore-backup" data-file="${esc_attr(backup.filename)}" ${!backup.verified ? 'disabled' : ''} title="${!backup.verified ? esc_attr(__('Cannot restore: File integrity failed.', 'optistate')) : esc_attr(__('Restore this backup', 'optistate'))}">
            <span class="dashicons dashicons-backup"></span> ${__('Restore', 'optistate')}
          </button>
          <button class="button delete-backup" data-file="${esc_attr(backup.filename)}">
            <span class="dashicons dashicons-trash"></span> ${__('Delete', 'optistate')}
          </button>
        </td>
      `;
      fragment.appendChild(row);
    });
    $backupsList[0].appendChild(fragment);
  }
  $createBackupBtn.on('click', function() {
    const $btn = $(this);
    if($btn.prop('disabled')) return;
    showOPTISTATEModal(
      __('💾 Create Backup', 'optistate'),
      __('Create a new database backup?', 'optistate') + '<br>' +
      __('The backup will process in the background.', 'optistate'),
      function() {
        $btn.prop('disabled', true);
        $backupSpinner.show();
        $btn.html(
          '<span class="spinner is-active" style="float:none;"></span> ' +
          '<strong>' + __('INITIATING ....', 'optistate') + '</strong>'
        );
        $(SELECTORS.globalButtons).prop('disabled', true);
        setTimeout(function() {
          $.ajax({
            url: optistate_BackupMgr.ajax_url,
            type: 'POST',
            data: {
              action: 'optistate_create_backup',
              nonce: optistate_BackupMgr.nonce
            },
            timeout: 30000,
            success: function(response) {
              if(response && response.success && response.data.status === 'starting') {
               let currentDbSizeBytes = 0;
               if (typeof statsCache !== 'undefined' && statsCache && statsCache.total_db_size_bytes) {
                   currentDbSizeBytes = parseInt(statsCache.total_db_size_bytes, 10);
               } else {
                   const currentDbSizeText = $dbSizeValue.text();
                   currentDbSizeBytes = parseSizeToBytes(currentDbSizeText);
               }
               const fullMessage = getBackupTimeEstimate(currentDbSizeBytes); 
               showToast(fullMessage, 'info');
                $btn.html(
                  '<span class="spinner is-active" style="float:none;"></span> ' +
                  __('<strong>BACKING UP ....</strong>', 'optistate')
                );
                $backupSpinner.hide();
                pollBackupStatus(response.data.transient_key, $btn);
              } else {
                const errorMsg = (response && response.data && response.data.message) ?
                  response.data.message : __('Backup failed to start.', 'optistate');
                showToast(errorMsg, 'error');
                $btn.prop('disabled', false).html(
                  '<span class="dashicons dashicons-plus-alt"></span> ' +
                  '<strong>' + __('Create Backup Now', 'optistate') + '</strong>'
                );
                $backupSpinner.hide();
                $(SELECTORS.globalButtons).prop('disabled', false);
              }
            },
            error: function(xhr) {
              if(xhr.status === 429) {
                showToast(__('🕐 Please wait before creating another backup.', 'optistate'), 'warning');
              } else {
                showToast(__('An error occurred while creating the backup.', 'optistate'), 'error');
              }
              $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-plus-alt"></span> ' +
                '<strong>' + __('Create Backup Now', 'optistate') + '</strong>'
              );
              $backupSpinner.hide();
              $(SELECTORS.globalButtons).prop('disabled', false);
            }
          });
        }, 800);
      }
    );
  });
  $backupsList.on('click', '.delete-backup', function() {
    const $btn = $(this);
    const filename = $btn.data('file');
    const $row = $btn.closest('tr');
    if(!filename) {
      return;
    }
    const message = sprintf(
      __('Are you sure you want to delete this backup?', 'optistate') +
      '<br>' + __('Backup: %s', 'optistate') +
      '<br><br>' + __('This action cannot be undone.', 'optistate'),
      esc_html(filename)
    );
    showOPTISTATEModal(
      __('🗑️ Confirm Deletion', 'optistate'),
      message,
      function() {
        $btn.prop('disabled', true);
        $.ajax({
          url: optistate_BackupMgr.ajax_url,
          type: 'POST',
          data: {
            action: 'optistate_delete_backup',
            nonce: optistate_BackupMgr.nonce,
            filename: filename
          },
          timeout: 30000,
          success: function(response) {
            if(response && response.success) {
              showToast(response.data.message, 'success');
              $row.fadeOut(300, function() {
                $(this).remove();
                if($backupsList.find('tr').length === 0) {
                  const emptyMsg = '<tr><td colspan="4" class="db-backup-empty">' +
                    __('No backups found. Create your first backup!', 'optistate') +
                    '</td></tr>';
                  $backupsList.html(emptyMsg);
                }
              });
            } else {
              const errorMsg = response && response.data && response.data.message ?
                response.data.message :
                __('Failed to delete backup.', 'optistate');
              showToast(errorMsg, 'error');
              $btn.prop('disabled', false);
            }
          },
          error: function() {
            showToast(__('An error occurred while deleting the backup.', 'optistate'), 'error');
            $btn.prop('disabled', false);
          }
        });
      }
    );
  });
  $backupsList.on('click', '.download-backup', function() {
    const $btn = $(this);
    const filename = $btn.data('file');
    if(!filename) return;
    if($btn.prop('disabled')) return;
    const baseUrl = optistate_BackupMgr.ajax_url.replace('admin-ajax.php', '');
    const downloadUrl = add_query_arg({
      action: 'optistate_backup_download',
      file: filename,
      _wpnonce: optistate_BackupMgr.nonce
    }, baseUrl);
    window.location.href = downloadUrl;
    showToast(__('Download starting...', 'optistate'), 'success');
  });

  function add_query_arg(args, url) {
    const params = new URLSearchParams();
    for(const key in args) {
      params.append(key, args[key]);
    }
    return url + (url.includes('?') ? '&' : '?') + params.toString();
  }
  let uploadedFilePath = null;
  let currentUpload = null;
  const MAX_FILE_SIZE = 3000 * 1024 * 1024;
  $('#optistate-file-input').on('change', function(e) {
    const file = e.target.files[0];
    if(!file) return;
    const fileName = file.name.toLowerCase();
    if(!fileName.endsWith('.sql') && !fileName.endsWith('.sql.gz')) {
      showToast(__('Only .sql and .sql.gz files are allowed!', 'optistate'), 'error');
      this.value = '';
      return;
    }
    if(file.size > MAX_FILE_SIZE) {
      showToast(__('File is too large! Maximum size is 3GB.', 'optistate'), 'error');
      this.value = '';
      return;
    }
    $('#optistate-file-name').text(esc_html(file.name));
    $('#optistate-file-size').text(formatBytes(file.size));
    $('#optistate-file-size').attr('data-bytes', file.size);
    $('#optistate-file-info').show();
    startChunkedUpload(file);
  });

  function startChunkedUpload(file) {
    const SMALL_FILE_THRESHOLD = 12 * 1024 * 1024;
    const SMALL_CHUNK_SIZE = 1 * 1024 * 1024;
    const LARGE_CHUNK_SIZE = 3 * 1024 * 1024;
    const CHUNK_SIZE = file.size < SMALL_FILE_THRESHOLD ? SMALL_CHUNK_SIZE : LARGE_CHUNK_SIZE;
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    const uploadId = generateUploadId();
    let currentChunk = 0;
    $('#optistate-upload-progress').show();
    $('.optistate-progress-fill').css('width', '0%').text('0%');
    currentUpload = {
      file: file,
      uploadId: uploadId,
      totalChunks: totalChunks,
      currentChunk: 0,
      cancelled: false,
      chunkSize: CHUNK_SIZE
    };
    uploadNextChunk();
  }

  function uploadNextChunk() {
    if(!currentUpload || currentUpload.cancelled) {
      resetUploadUI();
      return;
    }
    const CHUNK_SIZE = currentUpload.chunkSize;
    const file = currentUpload.file;
    const chunkIndex = currentUpload.currentChunk;
    const totalChunks = currentUpload.totalChunks;
    const start = chunkIndex * CHUNK_SIZE;
    const end = Math.min(start + CHUNK_SIZE, file.size);
    const chunk = file.slice(start, end);
    const reader = new FileReader();
    reader.onload = function(e) {
      const formData = new FormData();
      formData.append('action', 'optistate_upload_restore_file');
      formData.append('nonce', optistate_BackupMgr.nonce);
      formData.append('chunk', chunk);
      formData.append('chunk_index', chunkIndex);
      formData.append('total_chunks', totalChunks);
      formData.append('file_name', file.name);
      formData.append('file_size', file.size);
      formData.append('upload_id', currentUpload.uploadId);
      $.ajax({
        url: optistate_BackupMgr.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        timeout: 300000,
        success: function(response) {
          if(!currentUpload || currentUpload.cancelled) {
            resetUploadUI();
            return;
          }
          if(response && response.success) {
            const data = response.data;
            if(data.status === 'decompressing') {
              uploadedFilePath = 'DECOMPRESSING';
              $('.optistate-progress-fill')
                .css('width', '0%')
                .text(__('Starting decompression...', 'optistate'));
              $('#restore-button-wrapper').fadeIn(300);
              showToast(__('File uploaded! Decompression starting...', 'optistate'), 'success');
              currentUpload = null;
              if(data.decompression_key) {
                pollDecompressionStatus(data.decompression_key, $('#optistate-restore-file-btn'));
              }
              return;
            }
            if(data.complete) {
              uploadedFilePath = data.temp_path;
              if(data.file_size) {
                $('#optistate-file-size').text(data.file_size);
              }
              $('.optistate-progress-fill')
                .css('width', '100%')
                .text(__('100% - Validation complete. Ready to restore.', 'optistate'));
              $('#restore-button-wrapper').fadeIn(300);
              showToast(__('File uploaded and validated! Click "Restore from File" to proceed.', 'optistate'), 'success');
              currentUpload = null;
            } else {
              const percentComplete = data.progress || Math.round(((chunkIndex + 1) / totalChunks) * 100);
              $('.optistate-progress-fill')
                .css('width', percentComplete + '%')
                .text(percentComplete + '%');
              currentUpload.currentChunk++;
              uploadNextChunk();
            }
          } else {
            const errorMsg = response && response.data && response.data.message ?
              response.data.message :
              __('Upload failed!', 'optistate');
            showToast(errorMsg, 'error');
            currentUpload = null;
            resetUploadUI();
          }
        }
      });
    };
    reader.readAsArrayBuffer(chunk);
  }

  function generateUploadId() {
    let result = '';
    const characters = '0123456789abcdef';
    for(let i = 0; i < 32; i++) {
      result += characters.charAt(Math.floor(Math.random() * 16));
    }
    return result;
  }

  function resetUploadUI() {
    $('#optistate-upload-progress').hide();
    $('#optistate-file-info').hide();
    $('#optistate-file-input').val('');
    $('#restore-button-wrapper').hide();
    uploadedFilePath = null;
    currentUpload = null;
  }
  $body.on('click', '#optistate-restore-file-btn', function() {
    if(isRestoreInProgress) {
      showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
      return;
    }
    if(uploadedFilePath === 'DECOMPRESSING') {
      showToast(__('⏳ File is still being decompressed. Please wait...', 'optistate'), 'info');
      return;
    }
    if(!uploadedFilePath) {
      showToast(__('Select a SQL file from your device first!', 'optistate'), 'error');
      return;
    }
    const $button = $(this);
    const fileName = $('#optistate-file-name').text();
    let sizeInBytes = $('#optistate-file-size').attr('data-bytes');
    if (!sizeInBytes) {
        const fileSizeText = $('#optistate-file-size').text();
        sizeInBytes = parseSizeToBytes(fileSizeText);
    }
    const timeEstimate = getRestoreTimeEstimate(sizeInBytes);
    const message = '⚠️ ' + __('WARNING: Restore Database from File', 'optistate') +
      '<br><br>' + sprintf(__('File: %s', 'optistate'), esc_html(fileName)) +
      '<br><br>' + __('This will:', 'optistate') +
      '<br>' + __('• Create a safety backup first', 'optistate') +
      '<br>' + __('• Validate the database structure', 'optistate') +
      '<br>' + __('• Replace the current database', 'optistate') +
      '<br><br>' + __('ALL CURRENT DATA WILL BE REPLACED!', 'optistate') +
      '<br><br>' + __('Are you absolutely sure?', 'optistate');
    showOPTISTATEModal(
      __('🔐 Restore from File', 'optistate'),
      message,
      function() {
        if(!acquireRestoreLock()) {
          showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
          return;
        }
        $(SELECTORS.globalButtons).prop('disabled', true);
        $createBackupBtn.prop('disabled', true);
        const $wrapper = $('#restore-button-wrapper');
        $button.html(
          '<span class="spinner is-active" style="float:none;"></span> ' +
          __('INITIATING ....', 'optistate')
        );
        $wrapper.fadeIn(300);
        if(timeEstimate) {
          setTimeout(function() {
            showToast(timeEstimate, 'info');
          }, 1000);
        }
        $.ajax({
          url: optistate_BackupMgr.ajax_url,
          type: 'POST',
          data: {
            action: 'optistate_restore_from_file',
            nonce: optistate_BackupMgr.nonce,
            temp_path: uploadedFilePath
          },
          timeout: 1800000,
          success: function(response) {
            if(response && response.success) {
              if(response.data.status === 'decompressing') {
                $button.html(
                  '<span class="spinner is-active" style="float:none;"></span> ' +
                  __('DECOMPRESSING BACKUP ....', 'optistate')
                );
                pollDecompressionStatus(response.data.decompression_key, $button);
              } else if(response.data.status === 'starting') {
                $button.html(
                  '<span class="spinner is-active" style="float:none;"></span> ' +
                  __('CREATING SAFETY BACKUP ....', 'optistate')
                );
                pollRestoreStatus(response.data.master_restore_key, $button);
              }
            } else {
              const errorMsg = (response && response.data && response.data.message) ?
                response.data.message : __('Restore failed to start.', 'optistate');
              showToast(errorMsg, 'error');
              restoreButtonToDefault($button);
              $('.restore-backup').prop('disabled', false);
              $createBackupBtn.prop('disabled', false);
            }
          },
          error: function(xhr) {
            const errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ?
              xhr.responseJSON.data.message :
              __('Restore failed to start. Please try again.', 'optistate');
            showToast(errorMsg, 'error');
            restoreButtonToDefault($button);
            $('.restore-backup, .delete-backup').prop('disabled', false);
            $createBackupBtn.prop('disabled', false);
          }
        });
      }
    );
  });
  $backupsList.on('click', '.restore-backup', function() {
    if(isRestoreInProgress) {
      showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
      return;
    }
    const $btn = $(this);
    const filename = $btn.data('file');
    if(!filename) {
      return;
    }
    const $row = $btn.closest('tr');
    if($row.find('.db-backup-unverified').length > 0) {
      showToast(__('Restore Blocked: File integrity is compromised or unverified.', 'optistate'), 'error');
      $btn.prop('disabled', true);
      return;
    }
    let sizeInBytes = $row.data('bytes');
    if (!sizeInBytes) {
        const sizeText = $row.find('td').eq(2).text().trim();
        sizeInBytes = parseSizeToBytes(sizeText);
    }
    const timeEstimate = getRestoreTimeEstimate(sizeInBytes);
    const message = sprintf(
      __('This will restore your database from:', 'optistate') + '<br><br>' +
      __('%s', 'optistate') + '<br><br>' +
      __('Your site will enter maintenance mode briefly and then reload.', 'optistate') + '<br><br>' +
      __('ALL CURRENT DATA WILL BE REPLACED!', 'optistate') + '<br><br>' +
      __('Are you absolutely sure you want to continue?', 'optistate'),
      esc_html(filename)
    );
    showOPTISTATEModal(
      __('⚠️ WARNING: Restore Database', 'optistate'),
      message,
      function() {
        if(!acquireRestoreLock()) {
          showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
          return;
        }
        $(SELECTORS.globalButtons).prop('disabled', true);
        $createBackupBtn.prop('disabled', true);
        $btn.html(
          '<span class="spinner is-active" style="float:none;"></span> ' +
          '<strong>' + __('INITIATING ....', 'optistate') + '</strong>'
        );
        if(timeEstimate) {
          setTimeout(function() {
            showToast(timeEstimate, 'info');
          }, 1000);
        }
        $.ajax({
          url: optistate_BackupMgr.ajax_url,
          type: 'POST',
          data: {
            action: 'optistate_restore_backup',
            nonce: optistate_BackupMgr.nonce,
            filename: filename
          },
          timeout: 300000,
          success: function(response) {
            if(response && response.success) {
              if(response.data.status === 'decompressing') {
                $btn.html(
                  '<span class="spinner is-active" style="float:none;"></span> ' +
                  __('DECOMPRESSING BACKUP ....', 'optistate')
                );
                pollDecompressionStatus(response.data.decompression_key, $btn);
              } else if(response.data.status === 'starting') {
                $btn.html(
                  '<span class="spinner is-active" style="float:none;"></span> ' +
                  '<strong>' + __('CREATING SAFETY BACKUP ....', 'optistate') + '</strong>'
                );
                showToast(__('Restore initiated! The process is now running in the background.<br>You can close this page and check the Activity Log later.', 'optistate'), 'info');
                pollRestoreStatus(response.data.master_restore_key, $btn);
              }
            } else {
              const errorMsg = (response && response.data && response.data.message) ?
                response.data.message : __('Failed to initiate safety backup.', 'optistate');
              showToast(errorMsg, 'error');
              restoreButtonToDefault($btn);
              $('.restore-backup, #optistate-restore-file-btn').prop('disabled', false);
              $createBackupBtn.prop('disabled', false);
            }
          },
          error: function(xhr) {
            if(xhr.status === 429) {
              showToast(__('🕐 Please wait before restoring another backup.', 'optistate'), 'warning');
            } else {
              const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
                xhr.responseJSON.data.message : __('Failed to initiate safety backup (network error).', 'optistate');
              showToast(errorMsg, 'error');
            }
            restoreButtonToDefault($btn);
            $(SELECTORS.globalButtons).prop('disabled', false);
            $createBackupBtn.prop('disabled', false);
          }
        });
      }
    );
  });
  let isProcessing = false;
  const labels = {
    'post_revisions': __('Post Revisions', 'optistate'),
    'post_revisions_size': __('Revisions Data Size', 'optistate'),
    'expired_transients': __('Expired Transients', 'optistate'),
    'expired_transients_size': __('Expired Transients Data Size', 'optistate'),
    'table_overhead': __('Database Overhead', 'optistate'),
    'total_indexes_size': __('Total Indexes Size', 'optistate'),
    'total_tables_count': __('Number of Tables', 'optistate'),
    'db_creation_date': __('Database Created On', 'optistate'),
    'autoload_options': __('Autoloaded Options', 'optistate'),
    'autoload_size': __('Autoload Data Size', 'optistate'),
    'auto_drafts': __('Auto Drafts', 'optistate'),
    'trashed_posts': __('Trashed Posts', 'optistate'),
    'spam_comments': __('Spam Comments', 'optistate'),
    'trashed_comments': __('Trashed Comments', 'optistate'),
    'orphaned_postmeta': __('Orphaned Post Meta', 'optistate'),
    'orphaned_commentmeta': __('Orphaned Comment Meta', 'optistate'),
    'orphaned_relationships': __('Orphaned Term Relationships', 'optistate'),
    'orphaned_usermeta': __('Orphaned User Meta', 'optistate'),
    'duplicate_postmeta': __('Duplicate Post Meta', 'optistate'),
    'duplicate_commentmeta': __('Duplicate Comment Meta', 'optistate'),
    'unapproved_comments': __('Unapproved Comments', 'optistate'),
    'pingbacks': __('Pingbacks', 'optistate'),
    'trackbacks': __('Trackbacks', 'optistate'),
    'all_transients': __('All Transients (Non-expired)', 'optistate'),
    'as_completed': __('Completed Action Logs', 'optistate'),
    'action_scheduler': __('Action Logs', 'optistate'),
    'oembed_cache': __('oEmbed Cache', 'optistate'),
    'woo_bloat': __('WooCommerce Sessions/Logs', 'optistate'),
    'empty_taxonomies': __('Empty Taxonomies', 'optistate')
  };

  function showOPTISTATEModal(title, message, onConfirm, isDanger) {
    const safeTitle = esc_attr(title);
    const dangerClass = isDanger ? ' optistate-modal-danger' : '';
    const $overlay = $('<div class="optistate-modal-overlay"></div>');
    const $modal = $(`
      <div class="optistate-modal${dangerClass}">
        <div class="optistate-modal-header">
          <h3>${safeTitle}</h3>
          <button class="optistate-modal-close" aria-label="${esc_attr(__('Close', 'optistate'))}">&times;</button>
        </div>
        <div class="optistate-modal-body">${message}</div>
        <div class="optistate-modal-footer">
          <button class="button optistate-modal-cancel">${__('Cancel', 'optistate')}</button>
          <button class="button button-primary optistate-modal-confirm">${__('Confirm', 'optistate')}</button>
        </div>
      </div>
    `);
    $body.append($overlay).append($modal);
    setTimeout(() => {
      $overlay.addClass('show');
      $modal.addClass('show');
    }, 10);
    const closeModal = () => {
      $overlay.removeClass('show');
      $modal.removeClass('show');
      setTimeout(() => {
        $overlay.remove();
        $modal.remove();
      }, 300);
    };
    $modal.find('.optistate-modal-close, .optistate-modal-cancel').on('click', closeModal);
    $overlay.on('click', function(e) {
      if(e.target === this) closeModal();
    });
    $modal.find('.optistate-modal-confirm').on('click', function() {
      closeModal();
      if(onConfirm) onConfirm();
    });
    $modal.on('click', (e) => e.stopPropagation());
    $(document).one('keyup.OPTISTATE', function(e) {
      if(e.key === 'Escape') closeModal();
    });
  }

  function getBackupTimeEstimate(sizeInBytes) {
    const baseMsg = __('Database backup started!', 'optistate') + '<br>' +
                    __('You can close this page - process will continue in the background.', 'optistate');
    if (isNaN(sizeInBytes) || sizeInBytes <= 0) {
        return baseMsg;
    }
    const sizeMB = sizeInBytes / (1024 * 1024);
    let estimate = '';
    if (sizeMB < 130) {
       estimate = __('Less than 1 minute.', 'optistate');
    } else if (sizeMB < 280) {
       estimate = __('Less than 2 minutes', 'optistate');
    } else if (sizeMB < 760) {
       estimate = __('Less than 5 minutes.', 'optistate');
    } else if (sizeMB < 1600) {
       estimate = __('Less than 10 minutes.', 'optistate');
    } else if (sizeMB < 3300) {
       estimate = __('Less than 20 minutes.', 'optistate');
    } else if (sizeMB < 5000) {
       estimate = __('Less than 30 minutes.', 'optistate');
    } else {
       estimate = __('30+ minutes.', 'optistate');
    }
    return baseMsg + sprintf(__('<br>⏱️ Estimated time: %s', 'optistate'), estimate);
  }

  function getRestoreTimeEstimate(sizeInBytes) {
    if (isNaN(sizeInBytes) || sizeInBytes <= 0) {
        return __('Database restore started!<br>You can leave this page - process will continue in the background.', 'optistate');
    }
    const sizeMB = sizeInBytes / (1024 * 1024);
    if (sizeMB < 28) { 
      return __('Database restore started!<br>⏱️ Estimated time: Less than 1 minute.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else if (sizeMB < 85) {
      return __('Database restore started!<br>⏱️ Estimated time: Less than 3 minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else if (sizeMB < 130) {
      return __('Database restore started!<br>⏱️ Estimated time: Less than 5 minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else if (sizeMB < 280) {
      return __('Database restore started!<br>⏱️ Estimated time: Less than 10 minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else if (sizeMB < 600) {
      return __('Database restore started!<br>⏱️ Estimated time: Less than 20 minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else if (sizeMB < 940) {
      return __('Database restore started!<br>⏱️ Estimated time: Less than 30 minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    } else {
      return __('Database restore started!<br>⏱️ Estimated time: 30+ minutes.<br>You can leave this page - process will continue in the background.', 'optistate');
    }
  }

  function showToast(message, type = 'success') {
    const validTypes = ['success', 'error', 'warning', 'info'];
    const safeType = validTypes.includes(type) ? type : 'info';
    const $toast = $(`
      <div class="optistate-toast optistate-toast-${safeType}">
        <span class="optistate-toast-icon"></span>
        <span class="optistate-toast-message"></span>
        <button class="optistate-toast-close" aria-label="${__('Close notification', 'optistate')}" title="${__('Close', 'optistate')}">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    `);
    const $messageEl = $toast.find('.optistate-toast-message');
    const lines = String(message).split('<br>');
    lines.forEach((line, index) => {
      if(index > 0) $messageEl.append('<br>');
      $messageEl.append(document.createTextNode(line));
    });
    $body.append($toast);
    $toast.find('.optistate-toast-close').on('click', function() {
      $toast.removeClass('show');
      setTimeout(() => $toast.remove(), 300);
    });
    setTimeout(() => $toast.addClass('show'), 100);
    setTimeout(() => {
      $toast.removeClass('show');
      setTimeout(() => $toast.remove(), 300);
    }, 18000);
  }

  function handleAjaxError(xhr) {
    if(xhr.status === 429) {
      showToast(__('🕔 Please wait a few seconds before performing this action.', 'optistate'), 'warning');
    } else {
      const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
        xhr.responseJSON.data.message : __('An error occurred. Please try again.', 'optistate');
      showToast(errorMsg, 'error');
    }
    isProcessing = false;
  }
  let statsCache = null;
  let statsCacheTime = 0;
  const STATS_CACHE_DURATION = 15000;

  function loadStats(forceRefresh = false, showSuccessToast = false) {
    if(!forceRefresh && statsCache && (Date.now() - statsCacheTime) < STATS_CACHE_DURATION) {
      displayStats(statsCache);
      displayCleanupItems(statsCache);
      displayTargetedOps(statsCache);
      if (statsCache.formatted_total_size) {
          $dbSizeValue.text(statsCache.formatted_total_size);
      }
      return $.Deferred().resolve().promise();
    }
    $statsLoading.css({
      position: 'absolute',
      inset: 0,
      background: 'rgba(255,255,255,0.6)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      zIndex: 5
    }).html('<span class="spinner is-active"></span> ' +
      __('Generating database statistics...', 'optistate')).fadeIn(150);
    const data = {
      action: 'optistate_get_stats',
      nonce: optistate_Ajax.nonce
    };
    if(forceRefresh) data.force_refresh = true;
    const request = $.post(optistate_Ajax.ajaxurl, data)
      .done(function(response) {
        if(response && response.success && response.data) {
          const stats = response.data;
          statsCache = stats;
          statsCacheTime = Date.now();
          displayStats(stats);
          displayCleanupItems(stats);
          displayTargetedOps(stats);
          if (stats.formatted_total_size) {
             $dbSizeValue.text(stats.formatted_total_size);
          }
          if(forceRefresh) {
            loadHealthScore(false);
          }
          $statsLoading.fadeOut(150);
          if(showSuccessToast) {
            showToast(__('📈 Database statistics refreshed', 'optistate'), 'info');
          }
        } else {
          showToast(__('Failed to load statistics', 'optistate'), 'error');
          $statsLoading.fadeOut(150);
          $dbSizeValue.text(__('Error', 'optistate'));
        }
      })
      .fail(function(xhr) {
        handleAjaxError(xhr);
        $statsLoading.fadeOut(150);
        $dbSizeValue.text(__('Error', 'optistate'));
      });
    return request;
  }
  const loadStatsDebounced = debounce(loadStats, 1000);
  $refreshStatsBtn.on('click', function() {
    if(isProcessing) return;
    loadStats(true, true);
  });
  $('#optistate-refresh-targeted-btn').on('click', function() {
    const $btn = $(this);
    if($btn.prop('disabled') || isProcessing) return;
    const originalText = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 4px 0 0;"></span> ' + __('Refreshing...', 'optistate'));
    $('#optistate-targeted-ops').css('opacity', '0.5');
    $.when(loadStats(true, true)).always(function() {
        $btn.prop('disabled', false).html(originalText);
      $('#optistate-targeted-ops').css('opacity', '1');
    });
  });
  $body.on('click', '.optistate-refresh-cleanup-btn', function() {
    const $btn = $(this);
    if($btn.prop('disabled') || isProcessing) return;
    const originalHtml = $btn.html();
    $btn.prop('disabled', true)
        .html('<span class="spinner is-active" style="float:none; margin:0 4px 0 0;"></span> ' + __('Refreshing...', 'optistate'));
    $('#optistate-cleanup-items').css('opacity', '0.5');
    $.when(loadStats(true, true)).always(function() {
        $btn.prop('disabled', false).html(originalHtml);
        $('#optistate-cleanup-items').css('opacity', '1');
    });
  });
  $saveAutoOptimizeBtn.on('click', function(e) {
    e.preventDefault();
    const $btn = $(this);
    const autoOptimizeDays = parseInt($autoOptimizeDays.val(), 10);
    const autoOptimizeTime = $autoOptimizeTime.val();
    const emailNotifications = $emailNotifications.is(':checked');
    const autoBackupOnly = $autoBackupOnly.is(':checked');
    const maxBackups = parseInt($maxBackupsSetting.val(), 10);
    if(isNaN(maxBackups) || maxBackups < 1 || maxBackups > 10) {
      showToast(__('Please select a valid number of backups (1-10) in section 1.', 'optistate'), 'error');
      return;
    }
    if(isNaN(autoOptimizeDays) || autoOptimizeDays < 0 || autoOptimizeDays > 365) {
      showToast(__('Please enter a valid number between 0 and 365 for days.', 'optistate'), 'error');
      return;
    }
    if(!isValidTime(autoOptimizeTime)) {
      showToast(__('Please select a valid time from the dropdown.', 'optistate'), 'error');
      return;
    }
    $btn.prop('disabled', true).text('✓ ' + __('Saving...', 'optistate'));
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_save_auto_settings',
        nonce: optistate_Ajax.nonce,
        auto_optimize_days: autoOptimizeDays,
        auto_optimize_time: autoOptimizeTime,
        email_notifications: emailNotifications ? 1 : 0,
        auto_backup_only: autoBackupOnly ? 1 : 0,
        max_backups: maxBackups
      },
      timeout: 30000,
      success: function(response) {
        if(response && response.success) {
          showToast(response.data.message, 'success');
          if(response.data) {
            updateUIAfterSave(response.data);
          }
          loadOptimizationLog();
        } else {
          const errorMsg = response && response.data && response.data.message ?
            response.data.message :
            __('Failed to save settings.', 'optistate');
          showToast(errorMsg, 'error');
        }
      },
      error: function(xhr) {
        if(xhr.status === 429) {
          showToast(__('🕔 Please wait a few seconds before saving again.', 'optistate'), 'warning');
        } else {
          const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
            xhr.responseJSON.data.message : __('A network error occurred. Please try again.', 'optistate');
          showToast(errorMsg, 'error');
        }
      },
      complete: function() {
        $btn.prop('disabled', false).text('✓ ' + __('Save Settings', 'optistate'));
      }
    });
  });

  function validateDaysInput() {
    const $input = $autoOptimizeDays;
    let value = $input.val();
    value = value.replace(/\D/g, '');
    if(value.length > 3) {
      value = value.substring(0, 3);
    }
    const numValue = parseInt(value, 10) || 0;
    if(numValue > 365) {
      value = '365';
    }
    if($input.val() !== value) {
      $input.val(value);
    }
  }
  const $daysInput = $autoOptimizeDays;
  $daysInput.on('input', validateDaysInput);
  $daysInput.on('blur', validateDaysInput);
  $daysInput.on('change', validateDaysInput);
  $saveAutoOptimizeBtn.on('click', function() {
    validateDaysInput();
    const daysValue = parseInt($daysInput.val(), 10) || 0;
    if(daysValue > 365) {
      $daysInput.val('365');
      showToast(__('Maximum days allowed is 365', 'optistate'), 'warning');
    }
  });

  function isValidTime(time) {
    if(typeof time !== 'string' || time.length !== 5) return false;
    if(!/^\d{2}:\d{2}$/.test(time)) return false;
    const parts = time.split(':');
    const hour = parseInt(parts[0], 10);
    const minute = parseInt(parts[1], 10);
    return hour >= 0 && hour <= 23 && minute === 0;
  }

  function formatTimeForDisplay(time) {
    if(!isValidTime(time)) return __('Invalid Time', 'optistate');
    const parts = time.split(':');
    const hour = parseInt(parts[0], 10);
    let displayHour = hour % 12;
    if(displayHour === 0) displayHour = 12;
    const ampm = hour >= 12 ? 'PM' : 'AM';
    return `${displayHour}:00 ${ampm}`;
  }

  function updateUIAfterSave(data) {
    if(!data) return;
    const days = parseInt(data.days, 10) || 0;
    const time = data.time || '02:00';
    const emailEnabled = Boolean(data.email_notifications);
    const autoBackupOnly = Boolean(data.auto_backup_only);
    const $enabledSpan = $('#auto-status-enabled');
    const $disabledSpan = $('#auto-status-disabled');
    const $emailEnabledSpan = $('#email-status-enabled');
    const $emailDisabledSpan = $('#email-status-disabled');
    const $backupOnlyStatus = $('#auto-backup-only-status');
    const $taskDescFull = $('#auto-task-desc-full');
    const $taskDescBackupOnly = $('#auto-task-desc-backup-only');
    const timeDisplay = formatTimeForDisplay(time);
    if(days > 0) {
      let statusText;
      if(autoBackupOnly) {
        statusText = '✅ ' + sprintf(
          __('Automated *backup only* is enabled and will run every %d days at %s.', 'optistate'),
          days,
          esc_html(timeDisplay)
        );
        $taskDescFull.hide();
        $taskDescBackupOnly.show();
      } else {
        statusText = '✅ ' + sprintf(
          __('Automated *backup & cleanup* is enabled and will run every %d days at %s.', 'optistate'),
          days,
          esc_html(timeDisplay)
        );
        $taskDescFull.show();
        $taskDescBackupOnly.hide();
      }
      $enabledSpan.html(statusText);
      $enabledSpan.show();
      $disabledSpan.hide();
    } else {
      $disabledSpan.show();
      $enabledSpan.hide();
      $taskDescFull.show();
      $taskDescBackupOnly.hide();
    }
    if(emailEnabled) {
      $emailEnabledSpan.show();
      $emailDisabledSpan.hide();
    } else {
      $emailEnabledSpan.hide();
      $emailDisabledSpan.show();
    }
    if(autoBackupOnly) {
      $backupOnlyStatus.html('✅ ' + __('Backup Only mode is enabled.', 'optistate'));
    } else {
      $backupOnlyStatus.html('ℹ️ ' + __('Backup & Cleanup mode is enabled.', 'optistate'));
    }
    $autoOptimizeTime.val(time);
  }

  function updateAutoStatusDisplay() {
    const days = parseInt($autoOptimizeDays.val(), 10) || 0;
    const time = $autoOptimizeTime.val();
    const isBackupOnly = $autoBackupOnly.is(':checked');
    const $enabledSpan = $('#auto-status-enabled');
    const $disabledSpan = $('#auto-status-disabled');
    const $backupOnlyStatus = $('#auto-backup-only-status');
    const $taskDescFull = $('#auto-task-desc-full');
    const $taskDescBackupOnly = $('#auto-task-desc-backup-only');
    const timeDisplay = formatTimeForDisplay(time);
    if(days > 0) {
      let statusText;
      if(isBackupOnly) {
        statusText = '✅ ' + sprintf(
          __('Automated *backup only* is enabled and will run every %d days at %s.', 'optistate'),
          days,
          esc_html(timeDisplay)
        );
        $taskDescFull.hide();
        $taskDescBackupOnly.show();
      } else {
        statusText = '✅ ' + sprintf(
          __('Automated *backup & cleanup* is enabled and will run every %d days at %s.', 'optistate'),
          days,
          esc_html(timeDisplay)
        );
        $taskDescFull.show();
        $taskDescBackupOnly.hide();
      }
      $enabledSpan.html(statusText);
      $enabledSpan.show();
      $disabledSpan.hide();
    } else {
      $disabledSpan.show();
      $enabledSpan.hide();
      $taskDescFull.show();
      $taskDescBackupOnly.hide();
    }
    if(isBackupOnly) {
      $backupOnlyStatus.html('✅ ' + __('Backup Only mode is enabled.', 'optistate'));
    } else {
      $backupOnlyStatus.html('ℹ️ ' + __('Backup & Cleanup mode is enabled.', 'optistate'));
    }
  }
  const updateAutoStatusDisplayDebounced = debounce(updateAutoStatusDisplay, 300);
  $('#auto_optimize_days, #auto_optimize_time, #auto_backup_only').on('change input', updateAutoStatusDisplayDebounced);
  updateAutoStatusDisplay();

  function initializeHealthScore() {
    loadHealthScore();
    $refreshHealthScoreBtn.on('click', function() {
      loadHealthScore(true);
    });
  }

  function loadHealthScore(forceRefresh = false) {
    $healthScoreWrapper.css('position', 'relative');
    $healthScoreLoading.css({
      position: 'absolute',
      inset: 0,
      background: 'rgba(255,255,255,0.6)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      zIndex: 5
    }).fadeIn(150);
    $healthScoreWrapper.css('opacity', '0.5').show();
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_get_health_score',
        nonce: optistate_Ajax.nonce,
        force_refresh: forceRefresh
      },
      timeout: 60000,
      success: function(response) {
        if(response && response.success && response.data) {
          displayHealthScore(response.data);
          if(forceRefresh) {
            loadStats(false);
          }
        } else {
          const errorMsg = response && response.data && response.data.message ?
            response.data.message :
            __('Failed to load health score', 'optistate');
          showHealthScoreError(errorMsg);
        }
      },
      error: function(xhr) {
        let errorMsg = __('Network error loading health score', 'optistate');
        if(xhr.status === 429) {
          errorMsg = __('🕔 Please wait a few seconds before refreshing again.', 'optistate');
          showToast(errorMsg, 'warning');
        } else {
          if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMsg = xhr.responseJSON.data.message;
          }
          showHealthScoreError(errorMsg);
        }
      },
      complete: function() {
        $healthScoreLoading.fadeOut(150);
        $healthScoreWrapper.css('opacity', '1').show();
      }
    });
  }

  function displayHealthScore(scoreData) {
    if(!scoreData || typeof scoreData.overall_score === 'undefined') {
      return;
    }
    const overallScore = parseInt(scoreData.overall_score, 10);
    const $scoreValue = $('#health-score-value');
    $scoreValue.text(overallScore);
    $scoreValue.removeClass('score-excellent score-good score-fair score-poor score-critical');
    const $scoreCircle = $('.health-score-circle');
    let color = '';
    if(overallScore >= 90) {
      $scoreValue.addClass('score-excellent');
      color = '#28a745';
    } else if(overallScore >= 75) {
      $scoreValue.addClass('score-good');
      color = '#20c997';
    } else if(overallScore >= 60) {
      $scoreValue.addClass('score-fair');
      color = '#FFAD07';
    } else if(overallScore >= 40) {
      $scoreValue.addClass('score-poor');
      color = '#fd7e14';
    } else {
      $scoreValue.addClass('score-critical');
      color = '#dc3545';
    }
    $scoreCircle.css('border-color', color);
    if(scoreData.category_scores) {
      $('#health-score-performance').text(Math.round(scoreData.category_scores.performance || 0));
      $('#health-score-cleanliness').text(Math.round(scoreData.category_scores.cleanliness || 0));
      $('#health-score-efficiency').text(Math.round(scoreData.category_scores.efficiency || 0));
    }
    const $recommendations = $('#health-score-recommendations-list');
    $recommendations.empty();
    if(scoreData.recommendations && Array.isArray(scoreData.recommendations) && scoreData.recommendations.length > 0) {
      scoreData.recommendations.forEach(rec => {
        if(!rec.message || !rec.priority) return;
        const $recEl = $('<div class="recommendation-item"></div>');
        $recEl.addClass('recommendation-' + esc_attr(rec.priority));
        $recEl.text(rec.message);
        $recommendations.append($recEl);
      });
    } else {
      const $emptyState = $('<div class="recommendation-item recommendation-success"></div>');
      $emptyState.text(__('No recommendations at this time', 'optistate'));
      $recommendations.append($emptyState);
    }
  }

  function showHealthScoreError(message) {
    $('#health-score-recommendations-list').html(
      '<div class="recommendation-item recommendation-high">' +
      __('Error:', 'optistate') + ' » ' + esc_html(message) +
      '</div>'
    );
  }

  function refreshHealthScoreAfterOptimization() {
    setTimeout(() => loadHealthScore(true), 1000);
  }
  initializeHealthScore();
  $(document).on('optistate_optimization_complete', refreshHealthScoreAfterOptimization);

  function displayStats(stats) {
    if(!stats || typeof stats !== 'object') {
      return;
    }
    const $stats = $statsContainer;
    const currentHeight = $stats.outerHeight();
    if(currentHeight > 0) {
      $stats.css({
        minHeight: currentHeight
      });
    }
    const fragment = document.createDocumentFragment();
    for(const key in stats) {
      if(labels[key]) {
        let value = (stats[key] === false || stats[key] === null) ? '0 B' : stats[key];
        if(key === 'db_creation_date') {
          value = '<span style="white-space: nowrap;">' + esc_html(value) + '</span>';
        } else {
          value = esc_html(String(value));
        }
        const div = document.createElement('div');
        div.className = 'optistate-stat-item';
        div.innerHTML = `
          <div class="optistate-stat-label">${esc_html(labels[key])}</div>
          <div class="optistate-stat-value">${value}</div>
        `;
        fragment.appendChild(div);
      }
    }
  $stats[0].innerHTML = '';
    $stats[0].appendChild(fragment);
    $stats.css({
      minHeight: ''
    });
    loadOptimizationLog();
    const oneClickKeys = [
      'post_revisions', 'auto_drafts', 'trashed_comments',
      'expired_transients', 'duplicate_postmeta', 'duplicate_commentmeta',
      'orphaned_postmeta', 'orphaned_commentmeta', 'orphaned_relationships', 'orphaned_usermeta',
      'pingbacks', 'trackbacks'
    ];
    let oneClickCount = 0;
    oneClickKeys.forEach(key => {
      oneClickCount += (parseInt(stats[key], 10) || 0);
    });
    const $oneClickPreview = $('#optistate-one-click-count');
    if ($oneClickPreview.length) {
      $oneClickPreview.remove();
    }
    if (oneClickCount > 0) {
      $('#optistate-one-click').after(
        '<div id="optistate-one-click-count" style="margin-top: 18px; font-weight: 500; color: #666;">' +
        sprintf(__('🛈 %s items available to clean<br>Check the statistics for more details', 'optistate'), oneClickCount.toLocaleString()) +
        '</div>'
      );
    } else {
      $('#optistate-one-click').after(
        '<div id="optistate-one-click-count" style="margin-top: 18px; font-style: italic; color: #888;">' +
         __('✅ No safe items available to clean<br>Check the statistics for more details', 'optistate') +
        '</div>'
      );
    }
  }

  function displayTargetedOps(stats) {
    if(!stats || typeof stats !== 'object') return;
    const $container = $('#optistate-targeted-ops');
    if(!$container.length) return;
    const items = [{
        key: 'action_scheduler',
        icon: 'dashicons-calendar-alt',
        title: __('Action Logs', 'optistate'),
        desc: __('Cleans completed, failed, and canceled actions log.', 'optistate'),
        countKey: 'action_scheduler',
        btnText: __('Purge Actions', 'optistate'),
        safe: true
      },
      {
        key: 'oembed_cache',
        icon: 'dashicons-video-alt3',
        title: __('Embed Cache', 'optistate'),
        desc: __('Refreshes cached oEmbeds (YouTube, Twitter, etc).', 'optistate'),
        countKey: 'oembed_cache',
        btnText: __('Flush Cache', 'optistate'),
        safe: true
      },
      {
        key: 'woo_bloat',
        icon: 'dashicons-cart',
        title: __('WooCommerce Cleanup', 'optistate'),
        desc: __('Clears expired WooCommerce sessions, transients, and cache data.', 'optistate'),
        countKey: 'woo_bloat',
        btnText: __('Clear Sessions', 'optistate'),
        safe: true
      },
      {
        key: 'empty_taxonomies',
        icon: 'dashicons-tag',
        title: __('Empty Taxonomies', 'optistate'),
        desc: __('Removes empty categories and tags with 0 posts.', 'optistate'),
        countKey: 'empty_taxonomies',
        btnText: __('Delete Terms', 'optistate'),
        safe: false
      }
    ];
    $container.empty();
    items.forEach(item => {
      const count = parseInt(stats[item.countKey], 10) || 0;
      const countLabel = count > 0 
      ? `<span class="optistate-badge-count">${count.toLocaleString()}</span>` 
      : `<span class="optistate-badge-empty">0</span>`;
      const disabledAttr = 'disabled';
      const opacityStyle = 'opacity: 0.7;';
      const warningIcon = !item.safe ? 
        ` <span class="optistate-warning-icon" title="${esc_attr(__('Review carefully', 'optistate'))}">⚠️</span>` : '';
      const cardHtml = `
        <div class="optistate-card optistate-targeted-card" style="${opacityStyle}">
            <div class="targeted-header">
                <span class="dashicons ${item.icon}"></span>
                <h4>${item.title}${warningIcon}</h4>
            </div>
            <div class="targeted-stat">
                ${countLabel} ${__('items found', 'optistate')}
            </div>
            <p class="targeted-desc">${item.desc}</p>
            <button class="button optistate-clean-btn optistate-targeted-btn" 
                    data-type="${item.key}" 
                    data-safe="${item.safe}" 
                    ${disabledAttr}>
                ${item.btnText}
            </button>
        </div>
      `;
      $container.append(cardHtml);
    });
  }

  function loadOptimizationLog() {
    $.post(optistate_Ajax.ajaxurl, {
        action: 'optistate_get_optimization_log',
        nonce: optistate_Ajax.nonce
      })
      .done(function(response) {
        if(response && response.success && response.data) {
          displayOptimizationLog(response.data);
        }
      })
      .fail(() => {});
  }

  function displayOptimizationLog(log) {
    if(!Array.isArray(log)) {
      return;
    }
    let html = '<div class="optistate-log"><h3 style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;"><span><span class="dashicons dashicons-backup"></span> ' + __('Activity Log', 'optistate') + '</span><button type="button" class="button button-secondary button-small" id="optistate-refresh-log-btn" title="' + esc_attr(__('Refresh Activity Log', 'optistate')) + '">' + __('⟲ Refresh Logs', 'optistate') + '</button></h3><div class="optistate-log-list">';
    if(log.length === 0) {
      html += '<div class="optistate-log-empty">' +
        __('No significant events have been recorded yet.', 'optistate') +
        '</div>';
    } else {
      log.forEach(entry => {
        if(!entry.type || !entry.date || !entry.operation) return;
        const typeClass = entry.type === 'manual' ? 'manual' : 'scheduled';
        const typeLabel = entry.type === 'manual' ?
          __('Manual', 'optistate') :
          __('Scheduled', 'optistate');
        const operation = entry.operation || '🚀 ' + __('One-Click Optimization', 'optistate');
        html += `
          <div class="optistate-log-item">
            <span class="optistate-log-date">${esc_html(entry.date)}</span>
            <span class="optistate-log-operation">${esc_html(operation)}</span>
            <span class="optistate-log-type ${typeClass}">${esc_html(typeLabel)}</span>
          </div>
        `;
      });
    }
    html += '</div><div style="margin-top: 18px; color: #666;">ℹ️ This log displays 150 most recent events.</div></div>';
    $settingsLogContainer.html(html).hide().fadeIn(300);
  }

  function displayCleanupItems(stats) {
    if(!stats || typeof stats !== 'object') {
      return;
    }
    const items = [{
        key: 'post_revisions',
        title: __('Post Revisions', 'optistate'),
        desc: __('Old versions of posts and pages', 'optistate'),
        safe: true
      },
      {
        key: 'auto_drafts',
        title: __('Auto Drafts', 'optistate'),
        desc: __('Automatically saved drafts', 'optistate'),
        safe: true
      },
      {
        key: 'trashed_posts',
        title: __('Trashed Posts', 'optistate'),
        desc: __('Posts in trash', 'optistate'),
        safe: false
      },
      {
        key: 'spam_comments',
        title: __('Spam Comments', 'optistate'),
        desc: __('Comments marked as spam', 'optistate'),
        safe: false
      },
      {
        key: 'trashed_comments',
        title: __('Trashed Comments', 'optistate'),
        desc: __('Comments in trash', 'optistate'),
        safe: true
      },
      {
        key: 'orphaned_postmeta',
        title: __('Orphaned Post Meta', 'optistate'),
        desc: __('Metadata for deleted posts', 'optistate'),
        safe: true
      },
      {
        key: 'orphaned_commentmeta',
        title: __('Orphaned Comment Meta', 'optistate'),
        desc: __('Metadata for deleted comments', 'optistate'),
        safe: true
      },
      {
        key: 'orphaned_relationships',
        title: __('Orphaned Term Relationships', 'optistate'),
        desc: __('Term relationships for deleted posts', 'optistate'),
        safe: true
      },
      {
        key: 'expired_transients',
        title: __('Expired Transients', 'optistate'),
        desc: __('Expired temporary options', 'optistate'),
        safe: true
      },
      {
        key: 'all_transients',
        title: __('All Transients', 'optistate'),
        desc: __('All cached temporary data', 'optistate'),
        safe: false
      },
      {
        key: 'duplicate_postmeta',
        title: __('Duplicate Post Meta', 'optistate'),
        desc: __('Duplicate metadata entries', 'optistate'),
        safe: true
      },
      {
        key: 'duplicate_commentmeta',
        title: __('Duplicate Comment Meta', 'optistate'),
        desc: __('Duplicate comment metadata', 'optistate'),
        safe: true
      },
      {
        key: 'orphaned_usermeta',
        title: __('Orphaned User Meta', 'optistate'),
        desc: __('Metadata for deleted users', 'optistate'),
        safe: true
      },
      {
        key: 'unapproved_comments',
        title: __('Unapproved Comments', 'optistate'),
        desc: __('Comments awaiting moderation', 'optistate'),
        safe: false
      },
      {
        key: 'pingbacks',
        title: __('Pingbacks', 'optistate'),
        desc: __('Pingback notifications', 'optistate'),
        safe: true
      },
      {
        key: 'trackbacks',
        title: __('Trackbacks', 'optistate'),
        desc: __('Trackback notifications', 'optistate'),
        safe: true
      },
      {
        key: 'action_scheduler',
        title: __('Action Logs (PRO ONLY)', 'optistate'),
        desc: __('Completed, failed, and canceled actions log', 'optistate'),
        safe: true
      },
            {
        key: 'oembed_cache',
        title: __('oEmbed Cache (PRO ONLY)', 'optistate'),
        desc: __('Cached oEmbeds (YouTube, Twitter, etc)', 'optistate'),
        safe: true
      },
            {
        key: 'woo_bloat',
        title: __('WooCommerce Sessions/Logs (PRO ONLY)', 'optistate'),
        desc: __('Expired WooCommerce sessions, transients, cache data', 'optistate'),
        safe: true
      },
            {
        key: 'empty_taxonomies',
        title: __('Empty Taxonomies (PRO ONLY)', 'optistate'),
        desc: __('Empty categories and tags with 0 posts', 'optistate'),
        safe: false
      },
    ];
    const $container = $cleanupItemsContainer;
    const isFirstLoad = $container.is(':empty');
    const fragment = document.createDocumentFragment();
    items.forEach(item => {
      const count = parseInt(stats[item.key], 10) || 0;
      const warningIcon = !item.safe ?
        '<span class="optistate-warning-icon" title="' +
        esc_attr(__('Review before cleaning', 'optistate')) + '">⚠️</span>' : '';
      const disabled = count === 0 ? ' disabled' : '';
      const countClass = count > 0 ? 'has-items' : '';
      const div = document.createElement('div');
      div.className = `optistate-cleanup-item ${countClass}`;
      div.innerHTML = `
        <div class="optistate-cleanup-header">
          <span class="optistate-cleanup-title">${esc_html(item.title)} ${warningIcon}</span>
          <span class="optistate-cleanup-count">${count}</span>
        </div>
        <div class="optistate-cleanup-desc">${esc_html(item.desc)}</div>
        <button class="optistate-clean-btn" data-type="${esc_attr(item.key)}" 
                data-safe="${item.safe}"${disabled}>
          ${__('Clean Now', 'optistate')}
        </button>
      `;
      fragment.appendChild(div);
    });
    if(isFirstLoad) {
      $container[0].appendChild(fragment);
      $container.hide().fadeIn(300);
    } else {
      $container.stop(true, true).fadeTo(150, 0.3, function() {
        $container[0].innerHTML = '';
        $container[0].appendChild(fragment);
        $container.fadeTo(200, 1);
      });
    }
  }
  $body.on('click', '.optistate-clean-btn:not(:disabled)', function() {
    if(isProcessing) return;
    const $btn = $(this);
    const itemType = $btn.data('type');
    const isSafe = $btn.data('safe');
    if(!itemType) {
      return;
    }
    const itemName = labels[itemType] || itemType;
    const displayItemName = esc_html(itemName);
    const confirmMsg = '➜ ' + displayItemName + '<br><br>' + (isSafe ?
      __('Clean this item? This action cannot be undone.', 'optistate') :
      __('Make sure you no longer need these items.<br>Are you sure you want to continue?', 'optistate'));
    const title = isSafe ?
      '🧹 ' + __('Confirm Cleanup', 'optistate') :
      '⚠️ ' + __('Warning: Permanent Deletion', 'optistate');
    showOPTISTATEModal(title, confirmMsg, function() {
      isProcessing = true;
      $btn.prop('disabled', true).addClass('loading').text(__('🧹 Cleaning...', 'optistate'));
      $.post(optistate_Ajax.ajaxurl, {
          action: 'optistate_clean_item',
          nonce: optistate_Ajax.nonce,
          item_type: itemType
        })
        .done(function(response) {
          isProcessing = false;
          if(response && response.success) {
            $btn.removeClass('loading').addClass('success').text('✓ ' + __('Cleaned', 'optistate'));
            showToast(__('Successfully cleaned!', 'optistate'), 'success');
            setTimeout(() => loadStats(true), 2500);
          } else {
            const errorMsg = response && response.data ?
              response.data :
              __('Cleanup failed', 'optistate');
            $btn.removeClass('loading').prop('disabled', false).text(__('Error - Try Again', 'optistate'));
            showToast(errorMsg, 'error');
          }
        })
        .fail(function(xhr) {
          handleAjaxError(xhr);
          $btn.removeClass('loading').prop('disabled', false).text(__('Error - Try Again', 'optistate'));
        });
    }, !isSafe);
  });

  $('#optistate-optimize-tables').on('click', function() {
    if (isProcessing) return;
    const $btn = $(this);
    const message = __('This process performs a maintenance defragmentation on your database tables.', 'optistate') +
      '<br><br>' + __('• <strong>Reclaims unused space</strong> (data overhead)', 'optistate') +
      '<br>' + __('• <strong>Defragments data files</strong> for better I/O performance', 'optistate') +
      '<br><br>' + __('<strong>⚠️ Note:</strong> For very large databases, this operation might temporarily lock tables while they are being rebuilt.', 'optistate');
    showOPTISTATEModal(
      '⚡ ' + __('Optimize Database Tables', 'optistate'),
      message,
      function() {
        function runOptimizationStep() {
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_optimize_tables',
                nonce: optistate_Ajax.nonce
            })
            .done(function(response) {
                if (response && response.success && response.data) {
                    const data = response.data;
                    if (data.status === 'running') {
                        const percent = data.percentage || 0;
                        const message = data.message || ('⚡ ' + percent + '%');
                        $btn.text(message);
                        runOptimizationStep(); 
                        return;
                    }
                    const messages = [
                        sprintf(
                            __('Successfully optimized %d tables!', 'optistate'),
                            parseInt(data.optimized, 10) || 0
                        )
                    ];
                    if (data.reclaimed > 0) {
                        messages.push(
                            sprintf(
                                __('Reclaimed %s of space.', 'optistate'),
                                formatBytes(data.reclaimed)
                            )
                        );
                    }
                    if (data.skipped > 0) {
                        messages.push(
                            sprintf(
                                __('%d tables skipped (no optimization needed).', 'optistate'),
                                parseInt(data.skipped, 10)
                            )
                        );
                    }
                    if (data.failed > 0) {
                        messages.push(
                            sprintf(
                                __('%d tables failed to optimize.', 'optistate'),
                                parseInt(data.failed, 10)
                            )
                        );
                    }
                    const message = messages.join('<br>');
                    let detailsHtml = `<div class="optistate-success">${message}</div>`;
                    if(data.details && Array.isArray(data.details) && data.details.length > 0) {
                        detailsHtml += `<div class="optistate-details" style="margin-top: 10px; max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">`;
                        detailsHtml += `<strong>${__('Detailed Results:', 'optistate')}</strong><ul style="margin: 5px 0;">`;
                        data.details.forEach(detail => {
                            if(!detail.table || !detail.status) return;
                            let statusIcon = '⭕';
                            if(detail.status === 'optimized') statusIcon = '✅';
                            else if(detail.status === 'failed') statusIcon = '❌';
                            else if(detail.status === 'error') statusIcon = '⚠️';
                            detailsHtml += `<li>${statusIcon} ${esc_html(detail.table)}: ${esc_html(detail.status)}`;
                            if(detail.reclaimed) {
                                detailsHtml += ' (' + sprintf(
                                    __('reclaimed %s', 'optistate'),
                                    esc_html(detail.reclaimed)
                                ) + ')';
                            }
                            if(detail.error) detailsHtml += ` - ${esc_html(detail.error)}`;
                            detailsHtml += `</li>`;
                        });
                        detailsHtml += `</ul></div>`;
                    }
                    $('#optistate-table-results').addClass('show').html(detailsHtml).hide().fadeIn(300);
                    showToast(message, data.failed > 0 ? 'warning' : 'success');
                    isProcessing = false;
                    $('.optistate-advanced-op-btn').prop('disabled', false);
                    $btn.removeClass('loading').text('⚡ ' + __('Optimize All Tables', 'optistate'));
                } else {
                   handleAjaxError({ responseJSON: response });
                }
            })
            .fail(function(xhr) {
                handleAjaxError(xhr);
                isProcessing = false;
                $('.optistate-advanced-op-btn').prop('disabled', false);
                $btn.removeClass('loading').text('⚡ ' + __('Optimize All Tables', 'optistate'));
            });
        }
        isProcessing = true;
        $('.optistate-advanced-op-btn').prop('disabled', true);
        $btn.addClass('loading').text('⚡ ' + __('Starting...', 'optistate'));
        runOptimizationStep();
      }
    );
  });
  
  $('#optistate-table-analysis-results').on('click', '.optistate-delete-table-btn', function() {
      const $btn = $(this);
      const tableName = $btn.data('table');
      if (!tableName) return;
      const message = 
        '<div style="text-align: left;">' +
            '<span style="color: #d63638; font-weight: bold; font-size: 1.1em;">' +
                '⚠️ ' + __('CRITICAL WARNING: Permanent Data Loss', 'optistate') +
            '</span><br><br>' +
            
            sprintf(__('You are about to delete the table: <code>%s</code>', 'optistate'), esc_html(tableName)) + 
            '<br>' + 
            __('This action cannot be undone. If an active plugin is still using this table, features may break.', 'optistate') +
            
            '<div class="unused-db-table">' +
                '<strong>' + __('Required Verification Steps:', 'optistate') + '</strong>' +
                '<ul style="list-style: disc; margin-left: 20px; margin-top: 5px; margin-bottom: 0;">' +
                    '<li>' + __('Confirm the associated plugin/theme is fully uninstalled.', 'optistate') + '</li>' +
                    '<li>' + __('Ensure you have a recent database backup.', 'optistate') + '</li>' +
                '</ul>' +
            '</div>' +
            
            '<strong>' + __('Are you absolutely sure you want to proceed?', 'optistate') + '</strong>' +
        '</div>';
      showOPTISTATEModal(
          '🗑️ ' + __('Confirm Table Deletion', 'optistate'),
          message,
          function() {
              $btn.prop('disabled', true).text(__('Deleting...', 'optistate'));
              $.post(optistate_Ajax.ajaxurl, {
                  action: 'optistate_delete_table',
                  nonce: optistate_Ajax.nonce,
                  table_name: tableName
              })
              .done(function(response) {
                  if (response && response.success) {
                      showToast(response.data.message, 'success');
                      $btn.closest('.optistate-table-item').css('background-color', '#ffcccc').fadeOut(600, function() {
                          $(this).remove();
                      });
                      tableAnalysisCache = null; 
                  } else {
                      const errorMsg = response && response.data && response.data.message ?
                          response.data.message : __('Failed to delete table.', 'optistate');
                      showToast(errorMsg, 'error');
                      $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + __('Delete Table', 'optistate'));
                  }
              })
              .fail(function(xhr) {
                  handleAjaxError(xhr);
                  $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + __('Delete Table', 'optistate'));
              });
          },
          true
      );
  });
  $oneClickOptimizeBtn.on('click', function() {
    if(isProcessing) return;
    $('#optistate-one-click-count').fadeOut(200, function() { $(this).remove(); });
    const $btn = $(this);
    const message = __('This will perform a full database optimization including:', 'optistate') +
      '<br><br>' + __('• Clean post revisions', 'optistate') +
      '<br>' + __('• Delete auto-drafts', 'optistate') +
      '<br>' + __('• Delete trashed comments', 'optistate') +
      '<br>' + __('• Delete expired transients', 'optistate') +
      '<br>' + __('• Remove duplicate postmeta', 'optistate') +
      '<br>' + __('• Remove duplicate commentmeta', 'optistate') +
      '<br>' + __('• Remove orphaned data', 'optistate') +
      '<br>' + __('• Clean pingbacks and trackbacks', 'optistate') +
      '<br>' + __('• Optimize database tables', 'optistate') +
      '<br><br>' + __('This operation is safe but cannot be undone.', 'optistate');
    showOPTISTATEModal(
      '🚀 ' + __('Full Optimization', 'optistate'),
      message,
      function() {
        isProcessing = true;
        $btn.prop('disabled', true).addClass('loading').text('🧹 ' + __('Cleaning...', 'optistate'));
        $('#optistate-one-click-results').removeClass('show').html('').hide();
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_one_click_optimize',
            nonce: optistate_Ajax.nonce
          })
          .done(function(cleanup_response) {
            let html = '<div class="optistate-success"><strong>✅ ' +
              __('Cleanup Complete!', 'optistate') + '</strong></div>';
            if(cleanup_response && cleanup_response.success && cleanup_response.data) {
              for(const key in cleanup_response.data) {
                if(key === 'health_score') continue;
                const count = parseInt(cleanup_response.data[key], 10) || 0;
                html += '<div class="optistate-result-item">' + sprintf(
                  __('Cleaned %d %s', 'optistate'),
                  count,
                  esc_html(key.replace(/_/g, ' '))
                ) + '</div>';
              }
            }
            $('#optistate-one-click-results').addClass('show').html(html).hide().fadeIn(300);
            $btn.text('⚡ ' + __('Optimizing Tables...', 'optistate'));
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_optimize_tables',
                nonce: optistate_Ajax.nonce
              })
              .done(function(optimize_response) {
                if(optimize_response && optimize_response.success && optimize_response.data) {
                  const data = optimize_response.data;
                  const messages = [
                    sprintf(
                      __('Successfully optimized %d tables!', 'optistate'),
                      parseInt(data.optimized, 10) || 0
                    )
                  ];
                  if(data.reclaimed > 0) {
                    messages.push(
                      sprintf(
                        __('Reclaimed %s of space.', 'optistate'),
                        formatBytes(data.reclaimed)
                      )
                    );
                  }
                  let detailsHtml = `<div class="optistate-success" style="margin-top:10px;">${messages.join('<br>')}</div>`;
                  $('#optistate-one-click-results').append(detailsHtml);
                }
                showToast(__('Full optimization completed successfully!', 'optistate'), 'success');
                $(document).trigger('optistate_optimization_complete');
                $btn.removeClass('loading').prop('disabled', false).text('🚀 ' + __('Optimize Now', 'optistate'));
                isProcessing = false;
              })
              .fail(function(xhr) {
                handleAjaxError(xhr);
                showToast(__('Cleanup succeeded, but table optimization failed.', 'optistate'), 'error');
                $btn.removeClass('loading').prop('disabled', false).text('🚀 ' + __('Optimize Now', 'optistate'));
                isProcessing = false;
              });
          })
          .fail(function(xhr) {
            handleAjaxError(xhr);
            $btn.removeClass('loading').prop('disabled', false).text('🚀 ' + __('Optimize Now', 'optistate'));
            isProcessing = false;
          });
      },
      false
    );
  });
  $settingsLogContainer.on('click', '#optistate-refresh-log-btn', function() {
    const $btn = $(this);
    if($btn.prop('disabled')) return;
    const $icon = $btn.find('.dashicons');
    $btn.prop('disabled', true);
    $icon.addClass('is-active');
    $.post(optistate_Ajax.ajaxurl, {
        action: 'optistate_get_optimization_log',
        nonce: optistate_Ajax.nonce
      })
      .done(function(response) {
        if(response && response.success && response.data) {
          displayOptimizationLog(response.data);
          showToast(__('Activity Log refreshed', 'optistate'), 'info');
        } else {
          showToast(__('Failed to refresh log', 'optistate'), 'error');
        }
      })
      .fail(function() {
        showToast(__('Failed to refresh log (network error)', 'optistate'), 'error');
      })
      .always(function() {
        const $newBtn = $settingsLogContainer.find('#optistate-refresh-log-btn');
        $newBtn.prop('disabled', false);
        $newBtn.find('.dashicons').removeClass('is-active');
      });
  });
  loadStats();
  let tableAnalysisCache = null;
  $('#optistate-analyze-tables-btn').on('click', function() {
    const $btn = $(this);
    const $loading = $('#optistate-table-analysis-loading');
    const $results = $('#optistate-table-analysis-results');
    if(tableAnalysisCache && $results.is(':visible')) {
      $results.slideUp(300);
      $btn.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-search');
      return;
    }
    if(tableAnalysisCache && !$results.is(':visible')) {
      $results.slideDown(300);
      $btn.find('.dashicons').removeClass('dashicons-search').addClass('dashicons-arrow-up-alt2');
      return;
    }
    $btn.prop('disabled', true);
    $loading.fadeIn(200);
    $results.hide();
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_get_table_analysis',
        nonce: optistate_Ajax.nonce
      },
      timeout: 60000,
      success: function(response) {
        if(response && response.success && response.data) {
          tableAnalysisCache = response.data;
          displayTableAnalysis(response.data);
          $results.slideDown(300);
          $btn.find('.dashicons').removeClass('dashicons-search').addClass('dashicons-arrow-up-alt2');
        } else {
          const errorMsg = response && response.data ? response.data : __('Failed to analyze tables', 'optistate');
          showToast(errorMsg, 'error');
        }
      },
      error: function() {
        showToast(__('Network error while analyzing tables', 'optistate'), 'error');
      },
      complete: function() {
        $loading.fadeOut(200);
        $btn.prop('disabled', false);
      }
    });
  });

  function displayTableAnalysis(data) {
    if(!data || !data.totals) {
      return;
    }
    const $results = $('#optistate-table-analysis-results');
    const fragment = document.createDocumentFragment();
    let summaryHtml = '<div class="optistate-analysis-summary">';
    const dbNameHtml = data.db_name ? `<code style="color: #7C092E; font-weight: 500; background: #f1f1f1; padding: 3px 6px; border-radius: 3px;">Database Name: ${esc_html(data.db_name)}</code>` : '';
    summaryHtml += '<h4 style="display: flex; justify-content: space-between; align-items: center; width: 100%;">';
    summaryHtml += '<span><span class="dashicons dashicons-chart-bar"></span>' + __(' Database Summary', 'optistate') + '</span>';
    summaryHtml += dbNameHtml;
    summaryHtml += '</h4>';
    summaryHtml += '<div class="optistate-summary-grid">';
    summaryHtml += '<div class="optistate-summary-item">';
    summaryHtml += '<div class="optistate-summary-value">' + data.totals.total_tables + '</div>';
    summaryHtml += '<div class="optistate-summary-label">' + __('Total Tables', 'optistate') + '</div>';
    summaryHtml += '</div>';
    summaryHtml += '<div class="optistate-summary-item">';
    summaryHtml += '<div class="optistate-summary-value" style="color: #00a32a;">' + data.totals.core_count + '</div>';
    summaryHtml += '<div class="optistate-summary-label">' + __('WordPress Core', 'optistate') + '</div>';
    summaryHtml += '</div>';
    summaryHtml += '<div class="optistate-summary-item">';
    summaryHtml += '<div class="optistate-summary-value" style="color: #d63638;">' + data.totals.plugin_count + '</div>';
    summaryHtml += '<div class="optistate-summary-label">' + __('Plugin/Theme', 'optistate') + '</div>';
    summaryHtml += '</div>';
    summaryHtml += '<div class="optistate-summary-item">';
    summaryHtml += '<div class="optistate-summary-value">' + formatBytes(data.totals.total_size) + '</div>';
    summaryHtml += '<div class="optistate-summary-label">' + __('Total Size', 'optistate') + '</div>';
    summaryHtml += '</div></div></div>';
    if(data.totals.plugin_count > 0) {
      summaryHtml += '<div class="optistate-table-warning">';
      summaryHtml += '<strong>⚠️ ' + sprintf(__('Third-party tables detected', 'optistate'), data.totals.plugin_count) + '</strong><br>';
      summaryHtml += __('🔹 These tables belong to plugins or themes. They will be included in backups.<br>', 'optistate');
      summaryHtml += __('🔸 Tables marked with this icon 🕸️ may no longer be used.', 'optistate');
      summaryHtml += '</div>';
      summaryHtml += '<div class="optistate-table-info">';
      summaryHtml += '<strong>ℹ️️ ' + sprintf(__('Technical note', 'optistate'), data.totals.plugin_count) + '</strong><br>';
      summaryHtml += __('The "Last Updated" timestamps displayed in table details are retrieved from the INFORMATION_SCHEMA. However, please be aware that for InnoDB storage engines—the WordPress standard—this value is frequently missing or statically inaccurate. Performing operations such as OPTIMIZE TABLE will reset this timestamp to the current time.', 'optistate');
      summaryHtml += '</div>';
    }
    const summaryNode = document.createElement('div');
    summaryNode.innerHTML = summaryHtml;
    fragment.appendChild(summaryNode);
    const grid = document.createElement('div');
    grid.className = 'optistate-tables-grid';
    if(data.core_tables && data.core_tables.length > 0) {
      const coreCategory = document.createElement('div');
      coreCategory.className = 'optistate-table-category core-tables';
      coreCategory.innerHTML = '<h4><span class="dashicons dashicons-wordpress"></span>' + __('WordPress Core Tables', 'optistate') + ' (' + data.core_tables.length + ') - ' + formatBytes(data.totals.core_size) + '</h4>';
      data.core_tables.forEach(function(table) {
        coreCategory.appendChild(renderTableItem(table, true));
      });
      grid.appendChild(coreCategory);
    }
    if(data.plugin_tables && data.plugin_tables.length > 0) {
      const pluginCategory = document.createElement('div');
      pluginCategory.className = 'optistate-table-category plugin-tables';
      pluginCategory.innerHTML = '<h4><span class="dashicons dashicons-admin-plugins"></span>' + __('Plugin & Theme Tables', 'optistate') + ' (' + data.plugin_tables.length + ') - ' + formatBytes(data.totals.plugin_size) + '</h4>';
      data.plugin_tables.forEach(function(table) {
        pluginCategory.appendChild(renderTableItem(table, false));
      });
      grid.appendChild(pluginCategory);
    }
    fragment.appendChild(grid);
    $results.html('');
    $results[0].appendChild(fragment);
    $results.find('.optistate-table-toggle').on('click', function() {
      const $toggle = $(this);
      const $details = $toggle.siblings('.optistate-table-details');
      $details.toggleClass('show');
      if($details.hasClass('show')) {
        $toggle.text('▲ ' + __('Hide Details', 'optistate'));
      } else {
        $toggle.text('▼ ' + __('Show Details', 'optistate'));
      }
    });
  }
  function renderTableItem(table, isCore) {
    const tableClass = isCore ? 'core-table' : 'plugin-table';
    const overheadWarning = table.overhead > 1024 * 1024 ? ' ⚠️' : '';
    let abandonedHtml = '';
    if (table.is_abandoned) {
        abandonedHtml = ` <span class="optistate-abandoned-icon" title="${esc_attr(table.abandoned_text)}" style="cursor: help; font-size: 1.2em; vertical-align: middle;">🕸️</span>`;
    }
    const item = document.createElement('div');
    item.className = 'optistate-table-item ' + tableClass;
    let statsHtml = `
      <div class="optistate-table-stat"><strong>${__('Rows:', 'optistate')}</strong> ${table.rows.toLocaleString()}</div>
      <div class="optistate-table-stat"><strong>${__('Size:', 'optistate')}</strong> ${formatBytes(table.total_size)}</div>
    `;
    if (table.overhead > 0) {
        statsHtml += `<div class="optistate-table-stat"><strong>${__('Overhead:', 'optistate')}</strong> ${formatBytes(table.overhead)}${overheadWarning}</div>`;
    }
    statsHtml += `<div class="optistate-table-stat"><strong>${__('Engine:', 'optistate')}</strong> ${esc_html(table.engine)}</div>`;
    let detailsHtml = `
      <div><strong>${__('Data Size:', 'optistate')}</strong> ${formatBytes(table.data_size)}</div>
      <div><strong>${__('Index Size:', 'optistate')}</strong> ${formatBytes(table.index_size)}</div>
      <div><strong>${__('Collation:', 'optistate')}</strong> ${esc_html(table.collation)}</div>
    `;
    if (table.created) {
    detailsHtml += `<div><strong>${__('Created:', 'optistate')}</strong> ${table.created} (UTC)</div>`;
    }
    if (table.updated) {
        detailsHtml += `<div><strong>${__('Last Updated:', 'optistate')}</strong> ${table.updated} (UTC)</div>`;
    }
    if (!isCore && table.is_abandoned) {
        detailsHtml += `
        <div class="delete-db-table">
            <p style="margin: 0 0 10px 0; font-size: 13px; color: #b32d2e;">
                ${__('This table appears unused. Verify before deleting.', 'optistate')}
            </p>
            <button class="button optistate-delete-table-btn" data-table="${esc_attr(table.name)}" style="color: #b32d2e; border-color: #b32d2e;">
               🗑 ${__('Delete Table', 'optistate')}
            </button>
        </div>`;
    }
    item.innerHTML = `
      <div class="optistate-table-name">${esc_html(table.name)}${abandonedHtml}</div>
      <div class="optistate-table-description">${esc_html(table.description)}</div>
      <div class="optistate-table-stats">${statsHtml}</div>
      <button class="optistate-table-toggle">▼ ${__('Show Details', 'optistate')}</button>
      <div class="optistate-table-details">${detailsHtml}</div>
    `;
    return item;
}
  let $cacheStatsElements = null;

  function initCacheStatsElements() {
    if($cacheStatsElements === null) {
      $cacheStatsElements = {
        fileCount: $('#cache-file-count'),
        totalSize: $('#cache-total-size'),
        mobileCount: $('#cache-mobile-file-count'),
        avgSize: $('#cache-average-size'),
        lastWrite: $('#cache-last-write'),
        oldestPage: $('#cache-oldest-page')
      };
    }
    return $cacheStatsElements;
  }

  function loadCacheStats() {
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_get_cache_stats',
        nonce: optistate_Ajax.nonce
      },
      success: function(response) {
        const $elements = initCacheStatsElements();
        if(response && response.success) {
          requestAnimationFrame(() => {
            $elements.fileCount.text(response.data.file_count);
            $elements.totalSize.text(response.data.total_size);
            $elements.mobileCount.text(response.data.mobile_file_count);
            $elements.avgSize.text(response.data.average_size);
            $elements.lastWrite.text(response.data.last_write);
            $elements.oldestPage.text(response.data.oldest_page);
          });
        } else {
          const errorText = __('Error', 'optistate');
          requestAnimationFrame(() => {
            $elements.fileCount.text(errorText);
            $elements.totalSize.text(errorText);
            $elements.mobileCount.text(errorText);
            $elements.avgSize.text(errorText);
            $elements.lastWrite.text(errorText);
            $elements.oldestPage.text(errorText);
          });
        }
      },
      error: function() {
        const $elements = initCacheStatsElements();
        const errorText = __('Error', 'optistate');
        requestAnimationFrame(() => {
          $elements.fileCount.text(errorText);
          $elements.totalSize.text(errorText);
          $elements.mobileCount.text(errorText);
          $elements.avgSize.text(errorText);
          $elements.lastWrite.text(errorText);
          $elements.oldestPage.text(errorText);
        });
      }
    });
  }

  function initPerformanceFeatures() {
    loadPerformanceFeatures();
    $perfFeaturesContainer.on('change', '.optistate-performance-feature[data-feature="server_caching"] .optistate-feature-toggle input', function() {
      const $toggle = $(this);
      const $panel = $toggle.closest('.optistate-performance-feature').find('.server-cache-settings-panel');
      const $label = $toggle.closest('.optistate-feature-control').find('.optistate-toggle-label');
      const isChecked = $toggle.is(':checked');
      const newLabel = isChecked ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate');
      $label.text(newLabel);
      if(isChecked) {
        $panel.slideDown(300);
      } else {
        $panel.slideUp(300);
      }
    });
    $('#save-performance-features-btn').on('click', function() {
      if(isProcessing) return;
      const $btn = $(this);
      const features = {};
      $('.optistate-performance-feature').each(function() {
        const $feature = $(this);
        const featureKey = $feature.data('feature');
        if(featureKey === 'server_caching') {
          features.server_caching = {
            enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
            lifetime: $feature.find('#server-caching-lifetime').val(),
            query_string_mode: $feature.find('#server-caching-query-mode').val(),
            exclude_urls: $feature.find('#server-caching-exclude-urls').val(),
            mobile_cache: $feature.find('#server-caching-mobile-toggle').is(':checked'),
            disable_cookie_check: $feature.find('#server-caching-disable-cookie-check').is(':checked'),
            custom_consent_cookie: $feature.find('#server-caching-custom-cookie').val(),
            auto_preload: $feature.find('#server-caching-auto-preload').is(':checked')
          };
          return;
        }
        if(featureKey === 'cookie_banner_detection') {
          features.cookie_banner_detection = $feature.find('.optistate-feature-toggle input').is(':checked');
          return;
        }
        const $select = $feature.find('.optistate-feature-select');
        const $toggle = $feature.find('.optistate-feature-toggle input');
        if($select.length) {
          features[featureKey] = $select.val();
        } else if($toggle.length) {
          features[featureKey] = $toggle.is(':checked');
        }
      });
      isProcessing = true;
      $btn.prop('disabled', true).text('✓ ' + __('Saving...', 'optistate'));
      $.ajax({
        url: optistate_Ajax.ajaxurl,
        type: 'POST',
        data: {
          action: 'optistate_save_performance_features',
          nonce: optistate_Ajax.nonce,
          features: features
        },
        timeout: 30000,
        success: function(response) {
          if(response && response.success) {
            showToast(__('Performance settings have been saved successfully.', 'optistate'), 'success');
          } else {
            const errorMsg = response && response.data && response.data.message ?
              response.data.message :
              __('Failed to save settings.', 'optistate');
            showToast(errorMsg, 'error');
          }
        },
        error: function(xhr) {
          if(xhr.status === 429) {
            showToast(__('🕔 Please wait a few seconds before saving again.', 'optistate'), 'warning');
          } else {
            const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
              xhr.responseJSON.data.message : __('A network error occurred. Please try again.', 'optistate');
            showToast(errorMsg, 'error');
          }
        },
        complete: function() {
          isProcessing = false;
          $btn.prop('disabled', false).text('✓ ' + __('Save Performance Settings', 'optistate'));
        }
      });
    });
  }

  function loadPerformanceFeatures() {
    const $loading = $('#optistate-performance-features-loading');
    const $container = $perfFeaturesContainer;
    const $actions = $('#optistate-performance-features-actions');
    $loading.show();
    $container.hide();
    $actions.hide();
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_get_performance_features',
        nonce: optistate_Ajax.nonce
      },
      timeout: 30000,
      success: function(response) {
        if(response && response.success && response.data) {
          displayPerformanceFeatures(
            response.data.definitions,
            response.data.features,
            response.data.revisions_defined,
            response.data.trash_days_defined
          );
          $loading.hide();
          $container.fadeIn(300);
          $actions.fadeIn(300);
        } else {
          showToast(__('Failed to load performance features', 'optistate'), 'error');
        }
      },
      error: function() {
        showToast(__('Network error loading performance features', 'optistate'), 'error');
        $loading.hide();
      }
    });
  }

  function displayPerformanceFeatures(definitions, currentSettings, revisions_defined = false, trash_days_defined = false) {
    if(!definitions || typeof definitions !== 'object' || Array.isArray(definitions)) {
      showToast(__('Failed to load performance features: Invalid data structure', 'optistate'), 'error');
      return;
    }
    if(!currentSettings || typeof currentSettings !== 'object' || Array.isArray(currentSettings)) {
      showToast(__('Failed to load performance features: Invalid settings data', 'optistate'), 'error');
      return;
    }
    revisions_defined = Boolean(revisions_defined);
    trash_days_defined = Boolean(trash_days_defined);
    const $container = $perfFeaturesContainer;
    if(!$container.length) {
      return;
    }
    $container.empty();
    const fragment = document.createDocumentFragment();
    let htaccessStatus = null;
    if(definitions.hasOwnProperty('browser_caching')) {
      checkHtaccessStatus().then(status => {
        htaccessStatus = status;
        updateCachingFeatureDisplay(htaccessStatus);
      }).catch(error => {});
    }
    for(const key in definitions) {
      if(!definitions.hasOwnProperty(key)) {
        continue;
      }
      const feature = definitions[key];
      if(!feature || typeof feature !== 'object') {
        continue;
      }
      const currentValue = currentSettings.hasOwnProperty(key) ?
        currentSettings[key] :
        feature.default;
      const unsafeClass = !feature.safe ? ' feature-unsafe' : '';
      const warningBadge = !feature.safe ?
        '<span class="optistate-warning-badge">⚠️ ' + __('TEST CAREFULLY', 'optistate') + '</span>' : '';
      const proBadge = (key === 'db_query_caching') ?
        '<span style="font-size: 12px; font-weight: bold; margin-left: 8px; color: #007F6C;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">← PRO VERSION ONLY</a></span>' : '';
      const impactClass = 'impact-' + (feature.impact || 'low');
      const impactLabel = feature.impact === 'high' ? __('High Impact', 'optistate') :
        feature.impact === 'medium' ? __('Medium Impact', 'optistate') :
        __('Low Impact', 'optistate');
      const div = document.createElement('div');
      div.className = 'optistate-performance-feature' + unsafeClass;
      div.setAttribute('data-feature', esc_attr(key));
      let controlHTML = '';
      if(feature.type === 'custom_caching' && key === 'server_caching') {
        const settings = currentSettings.server_caching || feature.default;
        const checked = settings.enabled ? 'checked' : '';
        const status = settings.enabled ? __('✓ Active', 'optistate') : __('✗ Inactive', 'optistate');
        const mobile_checked = settings.mobile_cache ? 'checked' : '';
        const disable_cookie_check = settings.disable_cookie_check ? 'checked' : '';
        const auto_preload_checked = settings.auto_preload ? 'checked' : '';
        const lifetimeOptions = {
          3600: __('1 Hour', 'optistate'),
          7200: __('2 Hours', 'optistate'),
          21600: __('6 Hours', 'optistate'),
          43200: __('12 Hours', 'optistate'),
          86400: __('1 Day', 'optistate'),
          259200: __('3 Days', 'optistate'),
          604800: __('1 Week', 'optistate'),
          1209600: __('2 Weeks', 'optistate'),
          2592000: __('1 Month', 'optistate'),
          7776000: __('3 Months', 'optistate'),
          15552000: __('6 Months', 'optistate')
        };
        let lifetimeSelectHTML = '';
        for(const seconds in lifetimeOptions) {
          const selected = String(settings.lifetime) === seconds ? 'selected' : '';
          lifetimeSelectHTML += `<option value="${seconds}" ${selected}>${lifetimeOptions[seconds]}</option>`;
        }
        const queryModeOptions = {
          'ignore_all': __('1. Ignore All Query Strings', 'optistate'),
          'include_safe': __('2. Include Safe Query Strings (Recommended)', 'optistate'),
          'unique_cache': __('3. Unique Cache for All Query Strings (Advanced)', 'optistate')
        };
        let queryModeSelectHTML = '';
        for(const mode in queryModeOptions) {
          const selected = settings.query_string_mode === mode ? 'selected' : '';
          queryModeSelectHTML += `<option value="${mode}" ${selected}>${queryModeOptions[mode]}</option>`;
        }
        const queryModeExplanations = {
          'ignore_all': `
        <div class="query-mode-desc" data-mode="ignore_all">
            ${__('Serves the same cached page for all query strings.', 'optistate')}
            <br><strong>${__('Example:', 'optistate')}</strong> <code>/page?utm=123</code> ${__('serves the cache for', 'optistate')} <code>/page</code>.
            <br><strong>${__('Best for:', 'optistate')}</strong> ${__('Simple websites that do not use pagination (e.g., ?page=2) and search functionality.', 'optistate')}
            <br><strong>${__('🗑️ ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')}
        </div>`,
          'include_safe': `
        <div class="query-mode-desc" data-mode="include_safe">
            ${__('Creates unique cache files for "safe" parameters like pagination.', 'optistate')}
            <br><strong>${__('Example:', 'optistate')}</strong> <code>/blog?page=2</code> ${__('is cached separately from', 'optistate')} <code>/blog</code>.
            <br><strong>${__('Best for:', 'optistate')}</strong> ${__('Most websites, especially those with pagination, custom archives, search functionality.', 'optistate')}
            <br><strong>${__('🗑️ ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')}
        </div>`,
          'unique_cache': `
        <div class="query-mode-desc" data-mode="unique_cache">
            ${__('Creates a separate cache file for every unique query string.', 'optistate')}
            <br><strong>${__('Example:', 'optistate')}</strong> <code>/page?a=1</code> ${__('and', 'optistate')} <code>/page?a=2</code> ${__('are cached as two different files.', 'optistate')}
            <br><strong class="optistate-critical-warning">${__('⚠️ Warning:', 'optistate')}</strong> ${__('This can use a very large amount of disk space.', 'optistate')}
            <br><strong>${__('🗑 ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')}
        </div>`
        };
        controlHTML = `
    <div class="optistate-feature-control main-toggle">
        <label class="optistate-feature-toggle">
            <input type="checkbox" ${checked}>
            <span class="optistate-toggle-slider"></span>
        </label>
        <span class="optistate-toggle-label">${status}</span>
    </div>
    <div class="server-cache-settings-panel" style="${checked ? '' : 'display: none;'}">
        <div class="server-cache-panel-grid">
            <div class="cache-stats">
                <h4><span class="dashicons dashicons-chart-bar"></span> ${__('Cache Status', 'optistate')}</h4>
                <div class="stat-item"><strong>${__('Cached Pages:', 'optistate')}</strong> <span id="cache-file-count">...</span></div>
                <div class="stat-item"><strong>${__('Mobile Pages:', 'optistate')}</strong> <span id="cache-mobile-file-count">...</span></div>
                <div class="stat-item"><strong>${__('Total Size:', 'optistate')}</strong> <span id="cache-total-size">...</span></div>
                <div class="stat-item"><strong>${__('Avg. Page Size:', 'optistate')}</strong> <span id="cache-average-size">...</span></div>
                <div class="stat-item"><strong>${__('Last Write:', 'optistate')}</strong> <span id="cache-last-write">...</span></div>
                <div class="stat-item"><strong>${__('Oldest Page:', 'optistate')}</strong> <span id="cache-oldest-page">...</span></div>
                <button type="button" class="button button-secondary" id="purge-page-cache-btn">
                     ${__('🗑️ Purge All Cache', 'optistate')}
                </button>
                <div class="optistate-auto-preload-section">
                    <label for="server-caching-auto-preload">
                        <input type="checkbox" id="server-caching-auto-preload" disabled>
                        <strong>${__('🔋 Automatic Preload', 'optistate')}</strong>
                    </label>
                    <p class="optistate-auto-preload-description">
                        ${__('Automatically cache all pages from your sitemap after purging the cache.', 'optistate')}<br><br>
                        ${__('⚠️ Disable any cookie consent plugin before launching preload, then reactivate it upon completion.', 'optistate')}<br><br>
                        ${__('ℹ️ This process will take a while and consume storage space.', 'optistate')}<br><div style="font-size: 12px; font-weight: bold; margin-top: 0"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></div>
                    </p>
                    <div id="preload-progress-wrapper" class="optistate-preload-progress-wrapper">
                        <div class="optistate-preload-header">
                            <strong>${__('⌛ Preloading in progress...', 'optistate')}</strong>
                            <button type="button" class="button button-small" id="stop-preload-btn">
                                ${__('🟥 Stop', 'optistate')}
                            </button>
                        </div>
                        <div class="optistate-preload-bar-container">
                            <div id="preload-progress-bar" class="optistate-preload-bar">
                                0%
                            </div>
                        </div>
                        <div id="preload-status-text" class="optistate-preload-status">
                            ${__('Initializing...', 'optistate')}
                        </div>
                        <br>${__('⚠️ Do not close this page until 100% is reached.', 'optistate')}
                    </div>
                </div>
            </div>
            <div class="cache-settings">
                <h4><span class="dashicons dashicons-admin-settings"></span> ${__('Configuration', 'optistate')}</h4>
                <div class="setting-item">
                    <label for="server-caching-lifetime">${__('🕒 Cache Lifetime', 'optistate')}</label>
                    <select id="server-caching-lifetime">${lifetimeSelectHTML}</select>
                    ${__('How long a cached page is considered fresh. After this time, a new version will be generated.', 'optistate')}
                </div>
                <div class="setting-item">
                    <label for="server-caching-query-mode">${__('❓ Query String Handling', 'optistate')}</label>
                    <select id="server-caching-query-mode">${queryModeSelectHTML}</select>
                    <div id="query-mode-descriptions" class="optistate-query-mode-descriptions">
                        ${queryModeExplanations.ignore_all}
                        ${queryModeExplanations.include_safe}
                        ${queryModeExplanations.unique_cache}
                    </div>
                </div>
                <div class="setting-item">
                    <label for="server-caching-exclude-urls">${__('⛔️ Exclude Pages from Cache', 'optistate')}</label>
                    <textarea id="server-caching-exclude-urls" rows="6" placeholder="/cart/*&#10;/forum/*&#10;/my-custom-page/&#10;&#10;IMPORTANT: PURGE THE CACHE AFTER SAVING THESE CHANGES!">${esc_html(settings.exclude_urls || '')}</textarea>
                    <div class="optistate-smart-exclusions-info">
                        <strong>${__('💡 Smart Exclusions Already Active:', 'optistate')}</strong>
                        <p>
                            ${__('🔸 Logged-in users (never cached)', 'optistate')}<br>
                            ${__('🔸  Cart & checkout pages (auto-detected)', 'optistate')}<br>
                            ${__('🔸 URLs with tracking parameters (utm_*, fbclid, gclid)', 'optistate')}<br>
                            ${__('🔸 Search results & 404 pages', 'optistate')}<br>
                            ${__('🔸 Cookie banners (see "Smart Cookie Detection" below)', 'optistate')}
                        </p>
                    </div>
                    <div class="optistate-exclude-help">
                        ${__('Enter parts of URLs to exclude, one per line. Use * as a wildcard.', 'optistate')}
                    </div>
                    <div class="cache-examples">
                        <strong>${__('🎯 Examples:', 'optistate')}</strong>
                        ${__('🔹 To exclude a specific page:', 'optistate')} <code>/contact-us/</code><br>
                        ${__('🔹 To exclude all blog posts:', 'optistate')} <code>/blog/*</code><br>
                        ${__('🔹 To exclude member area:', 'optistate')} <code>/members/*</code><br>
                        ${__('✘ Wrong:', 'optistate')} https://www.yourwebsite.com<code>/contact-us/</code>
                    </div>
                </div>
                <div class="setting-item">
                   <label for="server-caching-mobile-toggle">${__('📲 Mobile-Specific Cache', 'optistate')}</label>
                    <label class="optistate-checkbox-label">
                        <input type="checkbox" id="server-caching-mobile-toggle" disabled ${mobile_checked}>
                        ${__('Create separate cache files for mobile devices.', 'optistate')}
                    </label>
                    ${__('Enable this ONLY if your site uses a different theme or layout for mobile visitors.', 'optistate')}<br>
                   <span style="font-size: 12px; font-weight: bold; margin-top: 0;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></span>
                </div>
                <div class="setting-item">
                    <label for="server-caching-disable-cookie-check" class="optistate-checkbox-label">
                        <input type="checkbox" id="server-caching-disable-cookie-check" ${disable_cookie_check}>
                        <strong>${__('🛡️ Disable Cookie Checks (Maximum Performance)', 'optistate')}</strong>
                    </label>
                    ${__('Check this option to serve cached pages to all visitors immediately for maximum performance.', 'optistate')}
                    <div class="optistate-warning-text">
                        <span class="optistate-warning-label">${__('⚠️ Warning:', 'optistate')}</span>
                        ${__('Only check this option if your site does not have any cookie banner/consent management plugin.', 'optistate')}
                    </div>
                </div>
                <div class="optistate-custom-cookie-section">
                    <label for="server-caching-custom-cookie" class="optistate-custom-cookie-label">
                        <strong>${__('⤷ Add Custom Consent Cookie', 'optistate')}</strong>
                    </label>
                   <input type="text" id="server-caching-custom-cookie" 
                           class="optistate-custom-cookie-input"
                           value="${esc_attr(settings.custom_consent_cookie || '')}" 
                           placeholder="${esc_attr(__('e.g., my_custom_consent_cookie', 'optistate'))}" disabled>
                    <p class="optistate-custom-cookie-help">
                        ${__('If your site uses a custom or unsupported cookie banner, add the cookie name here.', 'optistate')}<br>
                        ${__('This will ensure non-cached pages are served until this cookie is present.', 'optistate')}<br>
                       <span style="font-size: 12px; font-weight: bold; margin-top: 0;"><a style="color: #007F6C;" href="https://payhip.com/b/AS3Pt" target="_blank">↑ PRO VERSION ONLY ↑</a></span>
                    </p>
                </div>
            </div>
        </div>
        <div class="optistate-feature-info-box">
            <h4><span class="dashicons dashicons-shield-alt"></span> ${__('Smart Cookie Detection', 'optistate')}</h4>
            <p>${__('This caching feature automatically detects consent from major WordPress cookie plugins:', 'optistate')}</p>
            <ul>
               <li>${__('✔ Users WITH consent cookies ➝ see cached pages (fast).', 'optistate')}</li>
               <li>${__('✖ Users WITHOUT consent cookies ➝ see fresh pages (privacy-safe).', 'optistate')}</li>
            </ul>
            <p><strong>${__('Supported Plugins:', 'optistate')}</strong> CookieYes, Complianz, Cookie Notice, Borlabs Cookie, Real Cookie Banner, Cookiebot, OneTrust, Termly, Iubenda, GDPR Cookie Consent, and 10+ more.</p>
            <p class="optistate-tip-box" style="margin-bottom: 15px;">
                <strong>${__('💡 Tip:', 'optistate')}</strong> ${__('If you don\'t use any consent plugin, select "Disable Cookie Checks" above for best performance.', 'optistate')}
            </p>
        </div>
        <div class="optistate-feature-info-box">
            <h4><span class="dashicons dashicons-controls-play"></span> ${__('Automatic Cache Purging', 'optistate')}</h4>
            <p>${__('This feature is smart! You don\'t need to manually purge the cache every time you make a change. The cache for relevant pages is automatically cleared when you:', 'optistate')}</p>
            <ul>
                <li>${__('Publish or update a post or page.', 'optistate')}</li>
                <li>${__('Change a post\'s URL (slug).', 'optistate')}</li>
                <li>${__('Approve, unapprove, or delete a comment.', 'optistate')}</li>
                <li>${__('Update a category or tag.', 'optistate')}</li>
                <li>${__('Update your website menu.', 'optistate')}</li>
            </ul>
        </div>
    </div>`;
      } else if(feature.type === 'custom_db_caching' && key === 'db_query_caching') {
        // Free version: Always disabled, no settings panel
        const checked = '';
        const status = __('✗ Inactive', 'optistate');
        const isDisabled = 'disabled';
        const opacityStyle = 'style="opacity: 0.5;"';
        
        controlHTML = `
<div class="optistate-feature-control main-toggle" ${opacityStyle}>
    <label class="optistate-feature-toggle">
        <input type="checkbox" ${checked} ${isDisabled}>
        <span class="optistate-toggle-slider"></span>
    </label>
    <span class="optistate-toggle-label">${status}</span>
</div>`;
     } else if(feature.type === 'toggle') {
        const checked = currentValue ? 'checked' : '';
        const status = currentValue ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate');
        if(key === 'browser_caching') {
          controlHTML = `
                    <div class="optistate-feature-control">
                        <div id="htaccess-status-message" style="margin-bottom: 10px; padding: 10px; border-radius: 4px; display: none;">
                            <span id="htaccess-status-icon"></span>
                            <span id="htaccess-status-text"></span>
                        </div>
                        <label class="optistate-feature-toggle">
                            <input type="checkbox" ${checked} id="caching-toggle">
                            <span class="optistate-toggle-slider"></span>
                        </label>
                        <span class="optistate-toggle-label">${status}</span>
                    </div>
                `;
          } else {
          const isDisabled = feature.disabled ? 'disabled' : '';
          const opacityStyle = feature.disabled ? 'style="opacity: 0.5; cursor: not-allowed;"' : '';
          controlHTML = `
                    <div class="optistate-feature-control" ${opacityStyle}>
                        <label class="optistate-feature-toggle">
                            <input type="checkbox" ${checked} ${isDisabled}>
                            <span class="optistate-toggle-slider"></span>
                        </label>
                        <span class="optistate-toggle-label">${status}</span>
                    </div>
                `;
        }
      } else if(feature.options) {
        if(typeof feature.options !== 'object' || Array.isArray(feature.options)) {
          continue;
        }
        let optionsHTML = '';
        for(const optKey in feature.options) {
          if(!feature.options.hasOwnProperty(optKey)) {
            continue;
          }
          const selected = currentValue === optKey ? 'selected' : '';
          const optionLabel = feature.options[optKey];
          if(typeof optionLabel !== 'string') {
            continue;
          }
          optionsHTML += `<option value="${esc_attr(optKey)}" ${selected}>${esc_html(optionLabel)}</option>`;
        }
        const isDisabled = (key === 'post_revisions' && revisions_defined) ||
          (key === 'trash_auto_empty' && trash_days_defined);
        controlHTML = `
                <div class="optistate-feature-control">
                    <select class="optistate-feature-select" ${isDisabled ? 'disabled' : ''}>
                        ${optionsHTML}
                    </select>
                </div>
            `;
        if(key === 'post_revisions' && revisions_defined) {
          controlHTML += `
                    <div class="optistate-feature-warning" style="margin-top: 8px; padding: 8px 12px; background: #fff8e5; border-left: 3px solid #f1c40f; color: #555; line-height: 1.5em;">
                        <span class="dashicons dashicons-info-outline" style="vertical-align: middle; margin-right: 4px;"></span>
                        ${__('This setting is already defined in your wp-config.php file as WP_POST_REVISIONS. To change it, you must edit wp-config.php directly and remove the WP_POST_REVISIONS definition.', 'optistate')}
                    </div>
                `;
        }
        if(key === 'trash_auto_empty' && trash_days_defined) {
          controlHTML += `
                    <div class="optistate-feature-warning" style="margin-top: 8px; padding: 8px 12px; background: #fff8e5; border-left: 3px solid #f1c40f; color: #555; line-height: 1.5em;">
                        <span class="dashicons dashicons-info-outline" style="vertical-align: middle; margin-right: 4px;"></span>
                        ${__('This setting is already defined in your wp-config.php file as EMPTY_TRASH_DAYS. To change it, you must edit wp-config.php directly and remove the EMPTY_TRASH_DAYS definition.', 'optistate')}
                    </div>
                `;
        }
      }
      const featureTitle = feature.title || __('Unnamed Feature', 'optistate');
      const featureDescription = feature.description || __('No description available', 'optistate');
      div.innerHTML = `
            <div class="optistate-feature-header">
                <div class="optistate-feature-title">
                    ${esc_html(featureTitle)}
                    ${warningBadge}
                    ${proBadge}
                </div>
                <span class="optistate-feature-impact ${impactClass}">${impactLabel}</span>
            </div>
            <div class="optistate-feature-description">
                ${esc_html(featureDescription)}
            </div>
            ${controlHTML}
        `;
      fragment.appendChild(div);
    }
    if(fragment.childNodes.length === 0) {
      $container.html('<p style="padding: 20px; text-align: center; color: #666;">' +
        __('No performance features available.', 'optistate') + '</p>');
      return;
    }
    $container[0].appendChild(fragment);
    if($('#cache-file-count').length) {
      loadCacheStats();
    }
    const loadCacheStatsDebounced = debounce(loadCacheStats, 1000);
    $container.on('change', '#server-caching-query-mode', function() {
      const selectedMode = $(this).val();
      $('#query-mode-descriptions .query-mode-desc').hide();
      $(`#query-mode-descriptions .query-mode-desc[data-mode="${selectedMode}"]`).show();
    });
    $('#server-caching-query-mode').trigger('change');
    $container.on('change', '.optistate-feature-toggle input', function() {
      const $toggle = $(this);
      const $label = $toggle.closest('.optistate-feature-control').find('.optistate-toggle-label');
      if(!$label.length) {
        return;
      }
      const isChecked = $toggle.is(':checked');
      const newLabel = isChecked ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate');
      $label.text(newLabel);
    });
    $container.on('change', '.optistate-feature-select', function() {
      const $select = $(this);
      const $feature = $select.closest('.optistate-performance-feature');
      const featureKey = $feature.data('feature');
      if(featureKey) {}
    });
  }

  function checkHtaccessStatus() {
    return new Promise((resolve, reject) => {
      $.ajax({
        url: optistate_Ajax.ajaxurl,
        type: 'POST',
        data: {
          action: 'optistate_check_htaccess_status',
          nonce: optistate_Ajax.nonce
        },
        timeout: 10000,
        success: function(response) {
          if(response && response.success) {
            resolve({
              writable: true,
              message: response.data.message
            });
          } else {
            resolve({
              writable: false,
              message: response.data ? response.data.message : __('Failed to check .htaccess status', 'optistate'),
              exists: response.data ? response.data.exists : false
            });
          }
        },
        error: function() {
          resolve({
            writable: false,
            message: __('Network error while checking .htaccess status', 'optistate'),
            exists: false
          });
        }
      });
    });
  }

  function updateCachingFeatureDisplay(status) {
    const $statusMessage = $('#htaccess-status-message');
    const $statusIcon = $('#htaccess-status-icon');
    const $statusText = $('#htaccess-status-text');
    const $cachingToggle = $('#caching-toggle');
    if(!$statusMessage.length) return;
    if(status.writable) {
      $statusMessage.css({
        'background-color': '#d4edda',
        'border': '1px solid #c3e6cb',
        'color': '#155724'
      }).show();
      $statusIcon.html('✅');
      $statusText.html('<strong>' + __('Ready:', 'optistate') + '</strong> ' + status.message);
      $cachingToggle.prop('disabled', false);
    } else {
      $statusMessage.css({
        'background-color': '#f8d7da',
        'border': '1px solid #f5c6cb',
        'color': '#721c24'
      }).show();
      $statusIcon.html('⚠️');
      $statusText.html('<strong>' + __('Cannot Enable:', 'optistate') + '</strong> ' + status.message);
      $cachingToggle.prop('disabled', true).prop('checked', false);
      $cachingToggle.closest('.optistate-feature-control').find('.optistate-toggle-label')
        .text(__('✗ Inactive', 'optistate'));
      if(!status.exists) {
        $statusText.append('<br><small>' +
          __('The .htaccess file could not be created. This usually means WordPress does not have write permissions in the root directory.', 'optistate') +
          '</small>');
      } else {
        $statusText.append('<br><small>' +
          __('Try setting file permissions to 644 using your FTP client or hosting control panel.', 'optistate') +
          '</small>');
      }
    }
  }

  $perfFeaturesContainer.on('click', '#purge-page-cache-btn', function(e) {
    e.preventDefault();
    const $btn = $(this);
    if($btn.prop('disabled')) return;
    const cacheFileSize = $('#cache-total-size').text() || __('unknown size', 'optistate');
    const cacheFileCount = $('#cache-file-count').text() || __('an unknown number of', 'optistate');
    const message = '🗑️ ' + __('WARNING: Purge All Cache', 'optistate') +
      '<br><br>' +
      sprintf(
        __('You are about to delete all %s cached pages (total size: %s).', 'optistate'),
        esc_html(cacheFileCount),
        esc_html(cacheFileSize)
      ) +
      '<br><br>' +
      __('This is generally not required as the cache clears automatically on content updates. ', 'optistate') +
      __('Proceed only if you are certain.', 'optistate') +
      '<br><br>' +
      __('Are you sure you want to continue?', 'optistate');
    showOPTISTATEModal(
      __('Confirm Cache Purge', 'optistate'),
      message,
      function() {
        $btn.prop('disabled', true).html(
          '<span class="spinner is-active" style="float:none;"></span> ' +
          __('PURGING ....', 'optistate')
        );
        $.ajax({
          url: optistate_Ajax.ajaxurl,
          type: 'POST',
          data: {
            action: 'optistate_purge_page_cache',
            nonce: optistate_Ajax.nonce
          },
          timeout: 30000,
          success: function(response) {
            if(response && response.success) {
              showToast(response.data.message || __('Cache successfully purged!', 'optistate'), 'success');

              if(response.data.trigger_preload) {
                setTimeout(() => startPreload(), 1000);
              }
            } else {
              const errorMsg = response && response.data && response.data.message ?
                response.data.message :
                __('An error occurred during cache purging.', 'optistate');
              showToast(errorMsg, 'error');
            }
          },
          error: function(xhr) {
            if(xhr.status === 429) {
              showToast(__('🕔 Please wait a few seconds before purging the cache again.', 'optistate'), 'warning');
            } else {
              showToast(__('A network error occurred during cache purging.', 'optistate'), 'error');
            }
          },
          complete: function() {
            $btn.prop('disabled', false).html(
              '🗑️ ' + __('Purge All Cache', 'optistate')
            );

            if(typeof loadCacheStatsDebounced === 'function') {
              loadCacheStatsDebounced();
            } else if(typeof loadCacheStats === 'function') {
              loadCacheStats();
            }
          }
        });
      }
    );
  });
  initPerformanceFeatures();

  $('#optistate-export-settings-btn').on('click', function() {
    const $btn = $(this);
    const $status = $('#optistate-export-status');
    $btn.prop('disabled', true);
    $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' +
      '<span style="color: #666;">Preparing export...</span>');
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_export_settings',
        nonce: optistate_Ajax.nonce
      },
      success: function(response) {
        if(response.success) {
          window.location.href = response.data.download_url;
          $status.html('<p class="optistate-success">✓ ' + response.data.message + '</p>');
          setTimeout(function() {
            $status.fadeOut(300, function() {
              $(this).html('');
            }).fadeIn(300);
          }, 3000);
        } else {
          $status.html('<p class="optistate-error">✗ ' + (response.data.message || 'Export failed') + '</p>');
        }
      },
      error: function(xhr) {
        if(xhr.status === 429) {
          $status.html('<p class="optistate-error">✗ ' + __('🕔 Please wait a few seconds before trying again.', 'optistate') + '</p>');
        } else {
          const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
            xhr.responseJSON.data.message : 'Network error occurred';
          $status.html('<p class="optistate-error">✗ ' + errorMsg + '</p>');
        }
      },
      complete: function() {
        $btn.prop('disabled', false);
      }
    });
  });
  $('#optistate-settings-file-input').on('change', function() {
    const file = this.files[0];
    const $info = $('#optistate-settings-file-info');
    const $fileName = $('#optistate-settings-file-name');
    const $importBtn = $('#optistate-import-settings-btn');
    if(file) {
      if(!file.name.toLowerCase().endsWith('.json')) {
        alert('Please select a JSON file.');
        this.value = '';
        $info.hide();
        $importBtn.prop('disabled', true);
        return;
      }
      if(file.size > 1048576) {
        alert('File is too large. Maximum size is 1MB.');
        this.value = '';
        $info.hide();
        $importBtn.prop('disabled', true);
        return;
      }
      $fileName.text(file.name);
      $info.fadeIn(300);
      $importBtn.prop('disabled', false);
    } else {
      $info.hide();
      $importBtn.prop('disabled', true);
    }
  });
  $('#optistate-import-settings-btn').on('click', function() {
    const $btn = $(this);
    const $status = $('#optistate-import-status');
    const $fileInput = $('#optistate-settings-file-input');
    const file = $fileInput[0].files[0];
    if(!file) {
      $status.html('<p class="optistate-error">✗ Please select a file first</p>');
      return;
    }
    if(!confirm('This will replace all your current settings. Are you sure you want to continue?\n\nCurrent settings will be lost!')) {
      return;
    }
    $btn.prop('disabled', true);
    $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' +
      '<span style="color: #666;">Importing settings...</span>');
    const formData = new FormData();
    formData.append('action', 'optistate_import_settings');
    formData.append('nonce', optistate_Ajax.nonce);
    formData.append('settings_file', file);
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      success: function(response) {
        if(response.success) {
          let successMsg = '<p class="optistate-success">✓ ' + response.data.message + '</p>';
          if(response.data.summary) {
            successMsg += '<div style="margin-top: 10px; padding: 10px; background: white; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">';
            successMsg += '<strong>Import Summary:</strong><br>';
            successMsg += '• Max Backups: ' + response.data.summary.max_backups + '<br>';
            successMsg += '• Auto Optimize: Every ' + response.data.summary.auto_optimize_days + ' days<br>';
            successMsg += '• Email Notifications: ' + (response.data.summary.email_notifications ? 'Enabled' : 'Disabled') + '<br>';
            successMsg += '• Performance Features: ' + response.data.summary.performance_features_count + ' configured<br>';
            if(response.data.summary.imported_from_site) {
              successMsg += '• Exported From: ' + response.data.summary.imported_from_site + '<br>';
            }
            if(response.data.summary.exported_at) {
              successMsg += '• Exported On: ' + response.data.summary.exported_at;
            }
            successMsg += '</div>';
          }
          $status.html(successMsg);
          $fileInput.val('');
          $('#optistate-settings-file-info').hide();
          $btn.prop('disabled', true);
          setTimeout(function() {
            if(confirm('Settings imported successfully!\n\nReload the page to see all changes?')) {
              window.location.reload();
            }
          }, 2000);
        } else {
          $status.html('<p class="optistate-error">✗ ' + (response.data.message || 'Import failed') + '</p>');
          $btn.prop('disabled', false);
        }
      },
      error: function(xhr) {
        if(xhr.status === 429) {
          $status.html('<p class="optistate-error">✗ ' + __('🕔 Please wait a few seconds before trying again.', 'optistate') + '</p>');
        } else {
          const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
            xhr.responseJSON.data.message : 'Network error occurred';
          $status.html('<p class="optistate-error">✗ ' + errorMsg + '</p>');
        }
        $btn.prop('disabled', false);
      }
    });
  });

  $('body').on('click', '.optistate-integrity-info', function() {
    const status = $(this).data('status');
    let title, message;
    if(status === 'verified') {
      title = '🛡️ ' + __('File Integrity Verified', 'optistate');
      message = '<span style="margin-bottom: 15px;">' + __('<strong>Great news!</strong> This backup file is healthy and safe to use.', 'optistate') + '</span>' +
        '<p>' + __('<strong>What is File Integrity?</strong><br>When this backup was created, a unique digital fingerprint, or checksum, was generated.<br>An automatic scan confirmed that the backup matches its original fingerprint exactly, meaning that it has not been altered since its creation.', 'optistate') + '</p>' +
        '<div class="optistate-success" style="margin-top: 15px;">' +
        '✅ ' + __('No corruption detected.<br>It is safe to restore this backup.', 'optistate') +
        '</div>';
    } else {
      title = '⚠️ ' + __('File Integrity Check Failed', 'optistate');
      message = '<span style="margin-bottom: 15px;">' + __('<strong>Critical Warning:</strong> This backup file appears to be damaged or corrupted.', 'optistate') + '</span>' +
        '<p>' + __('<strong>Why is this unsafe?</strong><br>The current backup does not match its original digital fingerprint.<br>This means the data inside is incomplete or altered, which leads to SQL syntax errors.', 'optistate') + '</p>' +
        '<p><strong>' + __('Common Causes:', 'optistate') + '</strong></p>' +
        '<ul style="list-style-type: disc; margin-left: 20px; margin-bottom: 15px;">' +
        '<li>' + __('<strong>Interrupted Process:</strong> The backup process was interrupted before finishing.', 'optistate') + '</li>' +
        '<li>' + __('<strong>Disk Error:</strong> The server ran out of space while writing the backup file.', 'optistate') + '</li>' +
        '<li>' + __('<strong>Modification:</strong> The backup file was opened and saved manually.', 'optistate') + '</li>' +
        '</ul>' +
        '<div class="notice notice-error inline" style="margin-top: 15px; padding: 10px;">' +
        '<p style="margin: 0;">❌ <strong>DO NOT RESTORE:</strong> Using this file will likely crash your site. Please delete this backup and create a new one.</p>' +
        '</div>';
    }
    showOPTISTATEModal(
      title,
      message,
      function() {},
      status !== 'verified'
    );
    setTimeout(() => {
      $('.optistate-modal-confirm').text(__('OK, Understood', 'optistate'));
    }, 50);
  });

  function checkRestoreStatusOnLoad() {
    $.ajax({
      url: optistate_Ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'optistate_check_restore_status',
        nonce: optistate_Ajax.nonce
      },
      success: function(response) {
        if(response && response.success && response.data) {
          const data = response.data;
          if(data.status === 'running') {
            acquireRestoreLock();
            const $allRestoreButtons = $('.restore-backup, #optistate-restore-file-btn');
            let $button = $(data.button_selector);

            if(!$button.length) {
              $button = $allRestoreButtons.first();
            }
            if($button.length) {
              $allRestoreButtons.prop('disabled', true);
              $('.delete-backup').prop('disabled', true);
              $createBackupBtn.prop('disabled', true);
              $button.html('<span class="spinner is-active" style="float:none;"></span> ' + __('<strong>RESUMING ....</strong>', 'optistate'));
              if(data.button_selector === '#optistate-restore-file-btn') {
                $('#optistate-file-info').show();
                $('#optistate-upload-progress').show();
                $('#restore-button-wrapper').fadeIn(300);
                $('.optistate-progress-fill').css('width', '0%').text(__('<strong>RESUMING ....</strong>', 'optistate'));
              }
            }
            showToast(__('Previous database restore detected. Resuming monitoring...', 'optistate'), 'info');
            pollRestoreStatus(data.master_restore_key, $button);
          } else if(data.status === 'stalled') {
            showToast(__('A previous restore process stalled and has been aborted.', 'optistate'), 'error');
            $('.restore-backup, #optistate-restore-file-btn').each(function() {
              restoreButtonToDefault($(this));
            });
            $('.delete-backup').prop('disabled', false);
            $createBackupBtn.prop('disabled', false);
          }
        }
      }
    });
  }

  function checkBackupStatusOnLoad() {
    if(isProcessing) return;
    $.ajax({
      url: optistate_BackupMgr.ajax_url,
      type: 'POST',
      data: {
        action: 'optistate_check_manual_backup_on_load',
        nonce: optistate_BackupMgr.nonce
      },
      success: function(response) {
        if(response && response.success && response.data) {
          const data = response.data;
          if(data.status === 'running' && data.transient_key) {
            const $btn = $createBackupBtn;
            const $backupSpinner = $('#backup-spinner');
            $('.restore-backup, #optistate-restore-file-btn').prop('disabled', true);
            if($btn.length) {
              $btn.prop('disabled', true);
              $backupSpinner.hide();
              showToast(__('Resuming manual backup monitoring...', 'optistate'), 'info');
              pollBackupStatus(data.transient_key, $btn);
            }
          } else if(data.status === 'stalled') {
            showToast(__('A previous manual backup stalled or finished unmonitored. Please try again.', 'optistate'), 'warning');
          }
        }
      },
      error: function(xhr) {}
    });
  }
  checkRestoreStatusOnLoad();
  checkBackupStatusOnLoad();
  
    function processSearchReplaceChunk(action, params, $btn, $loading, $results, $statusText) {
    $.ajax({
        url: optistate_Ajax.ajaxurl,
        type: 'POST',
        data: Object.assign({
            action: action,
            nonce: optistate_Ajax.nonce
        }, params),
        success: function(response) {
            if (response.success) {
                if (response.data.status === 'running') {
                    if ($statusText.length && response.data.message) {
                        $statusText.text(response.data.message);
                    }
                    params.reset = false;
                    processSearchReplaceChunk(action, params, $btn, $loading, $results, $statusText);
                } 
                else if (action === 'optistate_search_replace_dry_run') {
                    const data = response.data.data || response.data;
                    renderDryRunResults(data, $results);
                    $loading.hide();
                    $btn.prop('disabled', false);
                    if ($('#optistate-sr-replace').val()) {
                        $('#optistate-sr-execute').prop('disabled', true);
                    }
                } 
                else if (action === 'optistate_search_replace_execute') {
                    const msg = response.data.message;
                    showToast(msg, 'success');
                    $results.html('<div class="optistate-success">✅ ' + msg + '</div>');
                    if(typeof loadCacheStats === 'function') loadCacheStats();
                    
                    $loading.hide();
                    $btn.prop('disabled', false);
                    $('#optistate-sr-dry-run').prop('disabled', false);
                }
            } else {
                showToast(response.data.message || 'Operation failed.', 'error');
                $loading.hide();
                $btn.prop('disabled', false);
                if (action === 'optistate_search_replace_execute') {
                    $('#optistate-sr-dry-run').prop('disabled', false);
                }
            }
        },
        error: function(xhr) {
            showToast('Network error during operation.', 'error');
            $loading.hide();
            $btn.prop('disabled', false);
            if (action === 'optistate_search_replace_execute') {
                $('#optistate-sr-dry-run').prop('disabled', false);
            }
        }
    });
  }

  function renderDryRunResults(data, $results) {
    let html = '';
    if (data.total_matches === 0) {
        html = '<div class="notice notice-info inline"><p>' + __('No matches found for this search term.', 'optistate') + '</p></div>';
    } else {
        html += '<div class="optistate-success" style="margin-bottom: 15px;">';
        html += '<strong>' + sprintf(__('Found %d matches across %d tables.', 'optistate'), data.total_matches, data.tables_affected) + '</strong>';
        html += '</div>';
        html += '<div style="max-height: 360px; overflow-y: auto; border: 1px solid #ddd;">';
        html += '<table class="widefat striped" style="border: 0;"><thead><tr><th>Table</th><th>Column</th><th>ID</th><th>Content Preview</th></tr></thead><tbody>';
        if (data.preview && data.preview.length > 0) {
            data.preview.forEach(function(item) {
                html += `<tr>
                    <td>${esc_html(item.table)}</td>
                    <td>${esc_html(item.column)}</td>
                    <td>${esc_html(item.id)}</td>
                    <td style="font-family: monospace; font-size: 12px;">${item.content}</td> 
                </tr>`; 
            });
        }
        if (data.total_matches > data.preview.length) {
            html += '<tr><td colspan="4" style="text-align: center; color: #666;"><em>' + sprintf(__('%d more matches...', 'optistate'), data.total_matches - data.preview.length) + '</em></td></tr>';
        }
        html += '</tbody></table></div>';
    }
    $results.html(html).slideDown(300);
  }

  $('#optistate-sr-dry-run').on('click', function() {
    const search = $('#optistate-sr-search').val();
    const tables = $('#optistate-sr-tables').val();
    const caseSensitive = $('#optistate-sr-case-sensitive').is(':checked'); 
    const $btn = $(this);
    const $loading = $('#optistate-sr-loading');
    const $results = $('#optistate-sr-results');
    const $execBtn = $('#optistate-sr-execute');
    if (!search) {
        showToast(__('Please enter a search term.', 'optistate'), 'error');
        return;
    }
    $btn.prop('disabled', true);
    $execBtn.prop('disabled', true);
    $loading.show();
    const $statusText = $loading.find('.sr-status-text');
    $statusText.text(wp.i18n.__('Initializing scan...', 'optistate'));
    $results.slideUp(200).empty();
    processSearchReplaceChunk('optistate_search_replace_dry_run', {
        search: search,
        tables: tables,
        case_sensitive: caseSensitive ? 1 : 0,
        reset: true
    }, $btn, $loading, $results, $statusText);
  });

  let activeTab = localStorage.getItem('optistate_active_tab') || '#tab-backups';
  $('.optistate-tab-content').hide();
  $(activeTab).show();
  $('.nav-tab-wrapper a').removeClass('nav-tab-active');
  $('.nav-tab-wrapper a[href="' + activeTab + '"]').addClass('nav-tab-active');
  $('.nav-tab-wrapper a').on('click', function(e) {
    e.preventDefault();
    const target = $(this).attr('href');
    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
    $(this).addClass('nav-tab-active');
    $('.optistate-tab-content').hide();
    $(target).show();
    localStorage.setItem('optistate_active_tab', target);
  });
  
  function getMetricColor(value, thresholds) {
      if (value <= thresholds.good) return '#28a745';
      if (value <= thresholds.needsImprovement) return '#ffa400';
      return '#dc3545';
  }
  function updatePageSpeedUI(data) {
    if (!data) return;
    const $metrics = $('#optistate-psi-metrics');
    const $circle = $('#psi-score-circle');
    const $score = $('#psi-score');
    const $tipsContainer = $('#optistate-psi-tips');
    const score = parseInt(data.score, 10);
    $score.text(score);
    let color = '#dc3545';
    if (score >= 90) color = '#28a745';
    else if (score >= 60) color = '#ffa400';
    $circle.css('border-color', color);
    $circle.css('color', '#333');
    const updateMetricCard = (id, metricData, thresholds) => {
        const $el = $(id);
        const $card = $el.closest('.optistate-targeted-card');
        const display = metricData.display || 'N/A';
        const value = metricData.value || 0;
        $el.text(`→ ${display}`);
        const metricColor = getMetricColor(value, thresholds);
        $card.css({
            'border-left': `4px solid ${metricColor}`,
            'padding-left': '11px'
        });
        if (value > thresholds.needsImprovement) return 'poor';
        if (value > thresholds.good) return 'average';
        return 'good';
    };
    const status = {
        fcp: updateMetricCard('#psi-fcp', data.fcp, { good: 1800, needsImprovement: 3000 }),
        lcp: updateMetricCard('#psi-lcp', data.lcp, { good: 2500, needsImprovement: 4000 }),
        cls: updateMetricCard('#psi-cls', data.cls, { good: 0.1, needsImprovement: 0.25 }),
        tbt: updateMetricCard('#psi-tbt', data.tbt, { good: 200, needsImprovement: 600 }),
        si:  updateMetricCard('#psi-si', data.si, { good: 3400, needsImprovement: 5800 }),
        tti: updateMetricCard('#psi-tti', data.tti, { good: 3800, needsImprovement: 7300 })
    };
    $('#psi-timestamp').text(data.timestamp + ' (' + data.strategy + ')');
    $metrics.css({ 'opacity': '1', 'pointer-events': 'auto' });
    let tipsHtml = '';
    let tipsCount = 0;
    const addTip = (icon, title, desc, linkTab) => {
        tipsCount++;
        tipsHtml += `
            <div class="optistate-tip-item" style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 15px; padding: 12px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px;">
                <span class="dashicons ${icon}" style="font-size: 24px; width: 24px; height: 24px; color: #2271b1; margin-top: 2px;"></span>
                <div>
                    <strong style="display: block; margin-bottom: 4px; color: #333;">${title}</strong>
                    <span style="color: #666; line-height: 1.5;">${desc}</span>
                    ${linkTab ? `<br><a href="${linkTab}" class="nav-tab-link" style="display: inline-block; margin-top: 6px; font-size: 13px; font-weight: 500; text-decoration: none;">${__('Go to Settings →', 'optistate')}</a>` : ''}
                </div>
            </div>`;
    };
    if (status.fcp !== 'good' || status.lcp !== 'good' || status.tti !== 'good') {
        addTip(
            'dashicons-superhero',
            __('Enable Caching Features', 'optistate'),
            __('Your loading speeds (FCP/LCP) can be improved. Go to the <strong>Performance</strong> tab and enable <strong>Server-Side Page Caching</strong> and <strong>Browser Caching</strong> to serve static HTML and assets instantly.', 'optistate'),
            '#tab-performance'
        );
    }
    if (status.tbt !== 'good' || status.si !== 'good') {
        addTip(
            'dashicons-database',
            __('Optimize Database & Autoload', 'optistate'),
            __('High blocking time often means the server is slow to respond. In the <strong>Advanced</strong> tab, run <strong>"Optimize All Tables"</strong> and <strong>"Optimize Autoloaded Options"</strong> to reduce database drag.', 'optistate'),
            '#tab-advanced'
        );
    }
    if (status.lcp !== 'good') {
        addTip(
            'dashicons-images-alt2',
            __('Enable Lazy Loading', 'optistate'),
            __('Images loading all at once slow down the Largest Contentful Paint (LCP). Enable <strong>Lazy Load Images</strong> in the <strong>Performance</strong> tab to defer off-screen images.', 'optistate'),
            '#tab-performance'
        );
    }
    if (status.tbt !== 'good') {
        addTip(
            'dashicons-heart',
            __('Control Heartbeat API', 'optistate'),
            __('Frequent admin-ajax calls can increase blocking time. In the <strong>Performance</strong> tab, try setting the <strong>Heartbeat API Control</strong> to "Slow Down" or "Disable" in non-essential areas.', 'optistate'),
            '#tab-performance'
        );
    }
    if (tipsCount < 2) {
        addTip(
            'dashicons-trash',
            __('Regular Cleanup', 'optistate'),
            __('Keep your metrics green by scheduling regular maintenance. Go to the <strong>Automation</strong> tab and ensure "Automatic Backup & Cleanup" is enabled.', 'optistate'),
            '#tab-automation'
        );
    }
    if (tipsHtml) {
        $tipsContainer.html(`
            <h3 style="margin-top: 30px; margin-bottom: 12px; font-size: 1.1em;">
                <span class="dashicons dashicons-lightbulb"></span> ${__('Performance Tips for Your Site', 'optistate')}
            </h3>
            ${tipsHtml}
        `).fadeIn(300);
        $tipsContainer.find('.nav-tab-link').on('click', function(e) {
            e.preventDefault();
            const target = $(this).attr('href');
            $('.nav-tab-wrapper a[href="' + target + '"]').trigger('click');
            window.scrollTo(0, 0);
        });
    } else {
        $tipsContainer.hide();
    }
  }
  function loadPageSpeedStats(forceRefresh = false) {
    if (isProcessing && forceRefresh) return;
    const $btn = $('#run-pagespeed-btn');
    const $metrics = $('#optistate-psi-metrics');
    if (forceRefresh) {
        isProcessing = true;
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;"></span> ' + __('Analyzing...', 'optistate'));
        $metrics.css('opacity', '0.5');
    }
    $.post(optistate_Ajax.ajaxurl, {
        action: 'optistate_run_pagespeed_audit',
        nonce: optistate_Ajax.nonce,
        strategy: $('#optistate-strategy').val(),
        force_refresh: forceRefresh
    }).done(function(response) {
        if (response.success) {
            updatePageSpeedUI(response.data);
            if (forceRefresh) {
                showToast(__('Performance audit completed successfully!', 'optistate'), 'success');
            }
        } else if (forceRefresh) {
            showToast(response.data.message || __('Audit failed.', 'optistate'), 'error');
        }
    }).fail(function(xhr) {
        if (forceRefresh) handleAjaxError(xhr);
    }).always(function() {
        if (forceRefresh) {
            isProcessing = false;
            $btn.prop('disabled', false).text(__('Run Audit', 'optistate'));
            $metrics.css('opacity', '1');
        }
    });
  }
  $('#save-pagespeed-key-btn').on('click', function() {
      const $btn = $(this);
      const key = $('#optistate_pagespeed_key').val().trim();
      if (!key) {
          showToast(wp.i18n.__('Please enter an API Key before saving.', 'optistate'), 'error');
          return;
      }
      $btn.prop('disabled', true).text('Saving...');
      $.post(optistate_Ajax.ajaxurl, {
          action: 'optistate_save_pagespeed_settings',
          nonce: optistate_Ajax.nonce,
          api_key: key
      }).done(function(response) {
          if (response.success) {
              showToast(response.data.message, 'success');
          } else {
              showToast(response.data.message, 'error');
          }
      }).fail(handleAjaxError)
      .always(function() {
          $btn.prop('disabled', false).text(wp.i18n.__('Save Key', 'optistate'));
      });
  });
  $('#run-pagespeed-btn').on('click', function() {
      loadPageSpeedStats(true);
  });
  if ($('#optistate-psi-metrics').length) {
      loadPageSpeedStats(false);
  }
});