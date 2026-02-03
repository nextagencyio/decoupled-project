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

          // Show success message
          showMessage(data.message, 'success');

          // Reset button
          button.textContent = '✅ Generated!';
          setTimeout(function () {
            button.textContent = originalText;
            button.disabled = false;
          }, 2000);
        } else {
          // Show error message
          showMessage(data.error || 'Failed to generate secrets', 'error');

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
        showMessage('Network error occurred', 'error');

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

    // Insert at top of page
    const container = document.querySelector('.dc-config-container');
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

})(Drupal);
