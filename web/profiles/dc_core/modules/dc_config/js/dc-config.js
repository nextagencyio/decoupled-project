/**
 * @file
 * JavaScript functionality for Decoupled Drupal configuration page.
 */

(function (Drupal) {
  'use strict';

  /**
   * Copy text to clipboard functionality.
   */
  Drupal.behaviors.decoupledConfigCopy = {
    attach: function (context, settings) {
      // Add event listeners to copy buttons
      once('dc-copy', '.dc-config-copy-button', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const targetId = this.getAttribute('data-target');
          const element = document.getElementById(targetId);
          if (element) {
            copyToClipboard(element, this);
          }
        });
      });
    }
  };

  /**
   * Download file functionality.
   */
  Drupal.behaviors.decoupledConfigDownload = {
    attach: function (context, settings) {
      // Add event listeners to download buttons
      once('dc-download', '.dc-config-download-button', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const targetId = this.getAttribute('data-target');
          const filename = this.getAttribute('data-filename') || 'download.txt';
          const element = document.getElementById(targetId);
          if (element) {
            downloadFile(element, filename, this);
          }
        });
      });
    }
  };

  /**
   * Copy text content to clipboard.
   *
   * @param {Element} element - The element containing text to copy.
   * @param {Element} button - The copy button element.
   */
  function copyToClipboard(element, button) {
    const text = element.textContent || element.innerText;

    if (navigator.clipboard && window.isSecureContext) {
      // Use the modern clipboard API.
      navigator.clipboard.writeText(text).then(function () {
        showCopySuccess(button);
      }).catch(function () {
        fallbackCopyText(text, button);
      });
    }
    else {
      // Fallback for older browsers.
      fallbackCopyText(text, button);
    }
  }

  /**
   * Fallback copy method for older browsers.
   *
   * @param {string} text - The text to copy.
   * @param {Element} button - The copy button element.
   */
  function fallbackCopyText(text, button) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    textArea.style.top = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand("copy");
      showCopySuccess(button);
    }
    catch (err) {
      console.error("Failed to copy: ", err);
      button.textContent = "❌ Failed";
      button.classList.add('dc-config-copy-button--error');
      setTimeout(function () {
        button.textContent = "📋 Copy";
        button.classList.remove('dc-config-copy-button--error');
      }, 2000);
    }

    textArea.remove();
  }

  /**
   * Show copy success feedback.
   *
   * @param {Element} button - The copy button element.
   */
  function showCopySuccess(button) {
    const originalText = button.textContent;
    button.textContent = "✅ Copied!";
    button.classList.add('dc-config-copy-button--success');
    setTimeout(function () {
      button.textContent = originalText;
      button.classList.remove('dc-config-copy-button--success');
    }, 2000);
  }

  /**
   * Download text content as a file.
   *
   * @param {Element} element - The element containing text to download.
   * @param {string} filename - The filename for the download.
   * @param {Element} button - The download button element.
   */
  function downloadFile(element, filename, button) {
    const text = element.textContent || element.innerText;
    
    try {
      // Create a blob with the text content
      const blob = new Blob([text], { type: 'text/plain' });
      
      // Create a temporary URL for the blob
      const url = window.URL.createObjectURL(blob);
      
      // Create a temporary anchor element and trigger download
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      a.style.display = 'none';
      
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      
      // Clean up the URL
      window.URL.revokeObjectURL(url);
      
      // Show success feedback
      showDownloadSuccess(button);
    }
    catch (err) {
      console.error("Failed to download: ", err);
      button.textContent = "❌ Failed";
      button.classList.add('dc-config-download-button--error');
      setTimeout(function () {
        button.textContent = "💾 Download";
        button.classList.remove('dc-config-download-button--error');
      }, 2000);
    }
  }

  /**
   * Show download success feedback.
   *
   * @param {Element} button - The download button element.
   */
  function showDownloadSuccess(button) {
    const originalText = button.textContent;
    button.textContent = "✅ Downloaded!";
    button.classList.add('dc-config-download-button--success');
    setTimeout(function () {
      button.textContent = originalText;
      button.classList.remove('dc-config-download-button--success');
    }, 2000);
  }

  /**
   * Generate secrets via AJAX.
   */
  Drupal.behaviors.decoupledConfigGenerateSecret = {
    attach: function (context, settings) {
      // Add event listener to generate secret button
      once('dc-generate-secret', '.dc-config-generate-button', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          generateSecretsAjax(button);
        });
      });
    }
  };

  /**
   * Generate secrets via AJAX call.
   *
   * @param {Element} button - The generate button element.
   */
  function generateSecretsAjax(button) {
    const form = button.closest('form');
    const formData = new FormData(form);
    const helpText = button.parentElement.querySelector('.dc-config-generate-help');

    // Show loading state
    const originalText = button.textContent;
    button.textContent = '⏳ Generating...';
    button.disabled = true;

    fetch('/dc-config/generate-secret-ajax', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Update both secrets in the code block
          updateSecrets(data.client_secret, data.revalidate_secret);

          // Show inline success message
          if (helpText) {
            helpText.textContent = '✅ ' + (data.message || 'New secrets generated successfully!');
            helpText.style.color = '#22c55e';
            setTimeout(function () {
              helpText.textContent = 'Generate a new OAuth client secret for enhanced security.';
              helpText.style.color = '';
            }, 5000);
          }

          // Reset button
          button.textContent = '✅ Generated!';
          setTimeout(function () {
            button.textContent = originalText;
            button.disabled = false;
          }, 2000);
        } else {
          // Show inline error message
          if (helpText) {
            helpText.textContent = '❌ ' + (data.error || 'Failed to generate secrets');
            helpText.style.color = '#ef4444';
            setTimeout(function () {
              helpText.textContent = 'Generate a new OAuth client secret for enhanced security.';
              helpText.style.color = '';
            }, 5000);
          }

          // Reset button
          button.textContent = '❌ Failed';
          setTimeout(function () {
            button.textContent = originalText;
            button.disabled = false;
          }, 3000);
        }
      })
      .catch(error => {
        console.error('Error:', error);

        // Show inline error message
        if (helpText) {
          helpText.textContent = '❌ Network error occurred';
          helpText.style.color = '#ef4444';
          setTimeout(function () {
            helpText.textContent = 'Generate a new OAuth client secret for enhanced security.';
            helpText.style.color = '';
          }, 5000);
        }

        // Reset button
        button.textContent = '❌ Error';
        setTimeout(function () {
          button.textContent = originalText;
          button.disabled = false;
        }, 3000);
      });
  }

  /**
 * Update both client secret and revalidation secret in the code block.
 */
  function updateSecrets(clientSecret, revalidateSecret) {
    const codeBlock = document.querySelector('.dc-config-code-block pre');
    if (codeBlock) {
      let content = codeBlock.textContent;

      // Update client secret
      content = content.replace(/DRUPAL_CLIENT_SECRET=.*$/m, `DRUPAL_CLIENT_SECRET=${clientSecret}`);

      // Update revalidation secret
      content = content.replace(/DRUPAL_REVALIDATE_SECRET=.*$/m, `DRUPAL_REVALIDATE_SECRET=${revalidateSecret}`);

      codeBlock.textContent = content;
    }
  }

  /**
   * Show message to user.
   */
  function showMessage(message, type) {
    // Create message element
    const messageDiv = document.createElement('div');
    messageDiv.className = `messages messages--${type}`;
    messageDiv.innerHTML = `<p>${message}</p>`;

    // Insert at top of main content area
    const container = document.querySelector('.dc-config-main-layout') ||
                      document.querySelector('.dc-config-container') ||
                      document.querySelector('main');
    if (container) {
      container.insertBefore(messageDiv, container.firstChild);

      // Auto-remove after 5 seconds
      setTimeout(function () {
        if (messageDiv.parentNode) {
          messageDiv.parentNode.removeChild(messageDiv);
        }
      }, 5000);
    }
  }

  // ============================================================
  // Vercel Integration
  // ============================================================

  /**
   * Vercel integration functionality.
   */
  Drupal.behaviors.decoupledConfigVercel = {
    attach: function (context, settings) {
      const projectSelect = document.getElementById('vercel-project');
      const syncButton = document.getElementById('vercel-sync-btn');

      if (!projectSelect || !syncButton) {
        return;
      }

      // Only run once
      if (projectSelect.dataset.initialized) {
        return;
      }
      projectSelect.dataset.initialized = 'true';

      // Load projects if connected
      if (settings.dcConfig && settings.dcConfig.vercelConnected) {
        loadVercelProjects(projectSelect, syncButton);
      }

      // Handle project selection
      projectSelect.addEventListener('change', function () {
        syncButton.disabled = !this.value;
      });

      // Handle sync button click
      syncButton.addEventListener('click', function () {
        syncToVercel(projectSelect, syncButton);
      });
    }
  };

  /**
   * Load Vercel projects via AJAX.
   */
  function loadVercelProjects(selectElement, syncButton) {
    fetch('/dc-config/vercel/projects')
      .then(response => response.json())
      .then(data => {
        if (data.success && data.projects) {
          // Clear existing options
          selectElement.innerHTML = '<option value="">Select a project...</option>';

          // Add projects
          data.projects.forEach(function (project) {
            const option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name;
            option.dataset.name = project.name;
            if (project.framework) {
              option.textContent += ` (${project.framework})`;
            }
            selectElement.appendChild(option);
          });

          // Pre-select if we have a connected project
          if (drupalSettings.dcConfig && drupalSettings.dcConfig.vercelProjectName) {
            const connectedProject = Array.from(selectElement.options).find(
              opt => opt.dataset.name === drupalSettings.dcConfig.vercelProjectName
            );
            if (connectedProject) {
              selectElement.value = connectedProject.value;
              syncButton.disabled = false;
            }
          }
        } else {
          selectElement.innerHTML = '<option value="">Error loading projects</option>';
        }
      })
      .catch(error => {
        console.error('Error loading Vercel projects:', error);
        selectElement.innerHTML = '<option value="">Error loading projects</option>';
      });
  }

  /**
   * Sync environment variables to Vercel.
   */
  function syncToVercel(selectElement, syncButton) {
    const projectId = selectElement.value;
    const projectName = selectElement.options[selectElement.selectedIndex].dataset.name;
    const statusEl = document.getElementById('vercel-sync-status');

    if (!projectId) {
      if (statusEl) {
        statusEl.textContent = 'Please select a project';
        statusEl.style.color = '#ef4444';
      }
      return;
    }

    // Show loading state
    const originalHTML = syncButton.innerHTML;
    syncButton.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="dc-config-spin"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Syncing...';
    syncButton.disabled = true;
    if (statusEl) {
      statusEl.textContent = '';
    }

    const formData = new FormData();
    formData.append('project_id', projectId);
    formData.append('project_name', projectName || '');
    formData.append('form_token', drupalSettings.dcConfig.csrfToken);

    fetch('/dc-config/vercel/sync', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          syncButton.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> Synced!';
          if (statusEl) {
            statusEl.innerHTML = '<span style="color: #22c55e;">' + (data.message || 'Environment variables synced!') + '</span>' +
              ' <a href="https://vercel.com/dashboard" target="_blank" style="color: #3b82f6; text-decoration: underline; margin-left: 0.5rem;">Open Vercel to redeploy →</a>';
          }

          // Update last synced text if it exists
          const lastSyncEl = document.querySelector('.dc-config-vercel-last-sync');
          if (lastSyncEl) {
            lastSyncEl.textContent = 'Last synced: Just now';
          }

          // Update sidebar checklist step 4 with a link
          updateChecklistWithVercelLink(projectName);
        } else {
          syncButton.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Failed';
          if (statusEl) {
            statusEl.textContent = data.error || 'Failed to sync to Vercel';
            statusEl.style.color = '#ef4444';
          }
        }

        setTimeout(function () {
          syncButton.innerHTML = originalHTML;
          syncButton.disabled = false;
        }, 3000);
      })
      .catch(error => {
        console.error('Error syncing to Vercel:', error);
        syncButton.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Error';
        if (statusEl) {
          statusEl.textContent = 'Network error occurred';
          statusEl.style.color = '#ef4444';
        }

        setTimeout(function () {
          syncButton.innerHTML = originalHTML;
          syncButton.disabled = false;
        }, 3000);
      });
  }

  /**
   * Update sidebar checklist step 4 with Vercel dashboard link.
   */
  function updateChecklistWithVercelLink(projectName) {
    const checklistItems = document.querySelectorAll('.dc-config-checklist-item');
    if (checklistItems.length >= 4) {
      const step4 = checklistItems[3]; // 0-indexed, so step 4 is index 3
      const descEl = step4.querySelector('.dc-config-step-description');
      if (descEl) {
        descEl.innerHTML = '<a href="https://vercel.com/dashboard" target="_blank" style="color: #3b82f6; text-decoration: underline;">Open Vercel Dashboard →</a>';
      }
    }
  }

  // ============================================================
  // Starter Selection & Import
  // ============================================================

  /**
   * Starter selection functionality.
   */
  Drupal.behaviors.decoupledConfigStarters = {
    attach: function (context, settings) {
      const starterCards = context.querySelectorAll('.dc-config-starter-card');
      const importBtn = document.getElementById('import-starter-btn');

      if (!starterCards.length || !importBtn) {
        return;
      }

      // Only initialize once
      if (importBtn.dataset.initialized) {
        return;
      }
      importBtn.dataset.initialized = 'true';

      let selectedStarter = null;

      // Handle starter card selection
      starterCards.forEach(function (card) {
        card.addEventListener('click', function () {
          // Remove selection from all cards
          starterCards.forEach(function (c) {
            c.classList.remove('dc-config-starter-card--selected');
          });

          // Select this card
          this.classList.add('dc-config-starter-card--selected');

          // Store selected starter data
          const nameEl = this.querySelector('.dc-config-starter-name');
          selectedStarter = {
            id: this.dataset.starterId,
            name: nameEl ? nameEl.textContent.trim() : this.dataset.starterId,
            contentUrl: this.dataset.contentUrl,
            vercelUrl: this.dataset.vercelUrl
          };

          // Enable import button only if starter has content
          importBtn.disabled = !selectedStarter.contentUrl;

          // Update Vercel deploy button URL if exists
          updateVercelDeployUrl(selectedStarter.vercelUrl);

          // Clear any previous status messages
          const statusEl = document.getElementById('import-status');
          if (statusEl) {
            statusEl.innerHTML = '';
          }
        });
      });

      // Handle import button click
      importBtn.addEventListener('click', function () {
        if (!selectedStarter || !selectedStarter.contentUrl) {
          return;
        }
        importStarterContent(selectedStarter, importBtn);
      });
    }
  };

  /**
   * Update Vercel deploy button URL based on selected starter.
   */
  function updateVercelDeployUrl(vercelUrl) {
    const deployBtn = document.getElementById('vercel-deploy-btn');
    if (deployBtn && vercelUrl) {
      deployBtn.href = vercelUrl;
    }
  }

  /**
   * Initialize Vercel deploy URL from default on page load.
   */
  Drupal.behaviors.decoupledConfigVercelUrl = {
    attach: function (context, settings) {
      const defaultUrlInput = document.getElementById('default-vercel-url');
      const deployBtn = document.getElementById('vercel-deploy-btn');

      if (defaultUrlInput && deployBtn && !deployBtn.dataset.initialized) {
        deployBtn.dataset.initialized = 'true';
        const defaultUrl = defaultUrlInput.value;
        if (defaultUrl) {
          deployBtn.href = defaultUrl;
        }
      }
    }
  };

  /**
   * Import starter content via AJAX.
   */
  function importStarterContent(starter, button) {
    const statusEl = document.getElementById('import-status');
    const originalText = button.textContent;

    // Show loading state
    button.disabled = true;
    button.textContent = 'Importing...';
    if (statusEl) {
      statusEl.innerHTML = '<span class="dc-config-import-loading">Fetching and importing content...</span>';
    }

    const formData = new FormData();
    formData.append('content_url', starter.contentUrl);
    formData.append('starter_id', starter.id);
    formData.append('starter_name', starter.name);

    fetch('/dc-config/import-starter', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Smoothly transition to installed state
          const starterSection = document.querySelector('.dc-config-starter-section');
          if (starterSection) {
            // Fade out current content
            starterSection.style.transition = 'opacity 0.3s ease';
            starterSection.style.opacity = '0';

            setTimeout(function () {
              // Replace with installed state
              starterSection.innerHTML = `
                <h2>Starter Template Installed</h2>
                <div class="dc-config-starter-installed">
                  <div class="dc-config-starter-installed-icon">&#10003;</div>
                  <div class="dc-config-starter-installed-text">
                    <strong>${starter.name}</strong> has been imported.
                    <p>Your site is configured with this starter's content types and sample data.</p>
                  </div>
                </div>
              `;
              // Fade in new content
              starterSection.style.opacity = '1';
            }, 300);
          }
        } else {
          button.textContent = 'Failed';
          if (statusEl) {
            statusEl.innerHTML = '<span class="dc-config-import-error">&#10007; ' +
              (data.error || 'Import failed') + '</span>';
          }

          setTimeout(function () {
            button.textContent = originalText;
            button.disabled = false;
          }, 3000);
        }
      })
      .catch(error => {
        console.error('Error importing starter:', error);
        button.textContent = 'Error';
        if (statusEl) {
          statusEl.innerHTML = '<span class="dc-config-import-error">&#10007; Network error occurred</span>';
        }

        setTimeout(function () {
          button.textContent = originalText;
          button.disabled = false;
        }, 3000);
      });
  }

})(Drupal);
