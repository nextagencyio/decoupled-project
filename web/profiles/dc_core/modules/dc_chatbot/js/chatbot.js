/**
 * @file
 * Decoupled Drupal Chatbot JavaScript functionality.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Decoupled Drupal Chatbot behavior.
   */
  Drupal.behaviors.decoupledChatbot = {
    attach: function (context, settings) {
      const container = context.querySelector('#dc-chatbot-container');
      if (!container || container.dataset.processed) {
        return;
      }

      container.dataset.processed = 'true';

      const chatbot = new DecoupledChatbot(container, settings.decoupledChatbot || {});
      chatbot.init();
    }
  };

  /**
   * Decoupled Drupal Chatbot class.
   */
  function DecoupledChatbot(container, settings) {
    this.container = container;
    this.settings = Object.assign({
      enabled: false,
      buttonPosition: 'bottom-right',
      buttonColor: '#007cba',
      showOnMobile: true,
      triggerDelay: 0,
      welcomeMessage: 'Hello! How can I help you today?',
      apiEndpoint: '/api/chat'
    }, settings);

    this.isOpen = false;
    this.messageHistory = [];
    this.currentMode = null; // 'model-content' or 'question-answer'
    this.modelContentStep = null; // Track model content flow step

    // DOM elements
    this.trigger = container.querySelector('#dc-chatbot-trigger');
    this.panel = container.querySelector('#dc-chatbot-panel');
    this.closeBtn = container.querySelector('#dc-chatbot-close');
    this.form = container.querySelector('#dc-chatbot-form');
    this.input = container.querySelector('#dc-chatbot-input');
    this.messages = container.querySelector('#dc-chatbot-messages');
    this.loading = container.querySelector('#dc-chatbot-loading');
    this.sendBtn = container.querySelector('.dc-chatbot-send');
    this.backdrop = document.querySelector('#dc-chatbot-backdrop');
    this.initialOptions = container.querySelector('#dc-chatbot-initial-options');
    this.inputContainer = container.querySelector('.dc-chatbot-input-container');
  }

  DecoupledChatbot.prototype.init = function () {
    if (!this.settings.enabled) {
      console.log('Decoupled Drupal Chatbot: Not enabled, skipping initialization');
      return;
    }

    console.log('Decoupled Drupal Chatbot: Initializing...', this.settings);

    // Initialize loading indicator - detach from DOM initially
    if (this.loading && this.loading.parentNode) {
      this.loading.parentNode.removeChild(this.loading);
      this.loading.setAttribute('aria-hidden', 'true');
      this.loading.style.display = 'none';
    }

    this.bindEvents();
    this.setupAutoTrigger();
    this.updateWelcomeTime();
    this.initializeInitialState();
  };

  DecoupledChatbot.prototype.bindEvents = function () {
    // Trigger button
    this.trigger.addEventListener('click', (e) => {
      e.preventDefault();
      this.toggle();
    });

    // Close button
    this.closeBtn.addEventListener('click', (e) => {
      e.preventDefault();
      this.close();
    });

    // Form submission
    this.form.addEventListener('submit', (e) => {
      e.preventDefault();
      this.sendMessage();
    });

    // Input events
    this.input.addEventListener('keypress', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.sendMessage();
      }
    });

    // Close on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isOpen) {
        this.close();
      }
    });

    // Close when clicking backdrop
    if (this.backdrop) {
      this.backdrop.addEventListener('click', (e) => {
        if (this.isOpen) {
          this.close();
        }
      });
    }

    // Action button handlers
    const actionButtons = this.container.querySelectorAll('.chatbot-action-btn');
    actionButtons.forEach(button => {
      button.addEventListener('click', (e) => {
        const action = button.getAttribute('data-action');
        this.handleActionButton(action);
      });
    });
  };

  DecoupledChatbot.prototype.setupAutoTrigger = function () {
    if (this.settings.triggerDelay > 0) {
      setTimeout(() => {
        if (!this.isOpen) {
          this.open();
        }
      }, this.settings.triggerDelay);
    }
  };

  DecoupledChatbot.prototype.updateWelcomeTime = function () {
    const welcomeTime = this.messages.querySelector('.dc-chatbot-welcome .message-time');
    if (welcomeTime) {
      welcomeTime.textContent = this.formatTime(new Date());
    }
  };

  DecoupledChatbot.prototype.toggle = function () {
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  };

  DecoupledChatbot.prototype.open = function () {
    this.panel.classList.add('open');
    this.panel.setAttribute('aria-hidden', 'false');
    this.isOpen = true;

    // Show backdrop
    if (this.backdrop) {
      this.backdrop.classList.add('show');
      this.backdrop.setAttribute('aria-hidden', 'false');
    }

    // Focus input
    setTimeout(() => {
      this.input.focus();
    }, 400);

    // Update trigger button
    this.trigger.setAttribute('aria-expanded', 'true');
  };

  DecoupledChatbot.prototype.close = function () {
    this.panel.classList.remove('open');
    this.panel.setAttribute('aria-hidden', 'true');
    this.isOpen = false;

    // Hide backdrop
    if (this.backdrop) {
      this.backdrop.classList.remove('show');
      this.backdrop.setAttribute('aria-hidden', 'true');
    }

    // Update trigger button
    this.trigger.setAttribute('aria-expanded', 'false');
    this.trigger.focus();
  };

  DecoupledChatbot.prototype.initializeInitialState = function () {
    // Keep input container visible but disabled initially, enable buttons
    this.inputContainer.classList.remove('hidden');
    this.disableInput();
    this.enableActionButtons();
  };

  DecoupledChatbot.prototype.returnToInitialState = function () {
    // Reset to initial state
    this.currentMode = null;
    this.modelContentStep = null;

    // Disable input and enable buttons
    this.disableInput();
    this.enableActionButtons();

    // Reset input placeholder
    this.input.placeholder = 'Type your message...';
    this.input.value = '';
  };

  DecoupledChatbot.prototype.enableActionButtons = function () {
    const buttons = this.container.querySelectorAll('.chatbot-action-btn');
    buttons.forEach(button => {
      button.disabled = false;
      button.style.opacity = '1';
      button.style.pointerEvents = 'auto';
    });
    this.initialOptions.classList.remove('hidden');
  };

  DecoupledChatbot.prototype.disableActionButtons = function () {
    const buttons = this.container.querySelectorAll('.chatbot-action-btn');
    buttons.forEach(button => {
      button.disabled = true;
      button.style.opacity = '0.5';
      button.style.pointerEvents = 'none';
    });
    // During workflows, keep buttons visible but disabled
    // During completion, this will be overridden by hideActionButtons
    this.initialOptions.classList.remove('hidden');
    this.initialOptions.style.display = 'block';
  };

  DecoupledChatbot.prototype.hideActionButtons = function () {
    // Hide the action buttons completely (used after model content completion)
    this.initialOptions.classList.add('hidden');
  };

  DecoupledChatbot.prototype.enableInput = function () {
    this.input.disabled = false;
    this.sendBtn.disabled = false;
    this.input.style.opacity = '1';
    this.sendBtn.style.opacity = '1';
    this.input.style.pointerEvents = 'auto';
    this.sendBtn.style.pointerEvents = 'auto';
  };

  DecoupledChatbot.prototype.disableInput = function () {
    this.input.disabled = true;
    this.sendBtn.disabled = true;
    this.input.style.opacity = '0.5';
    this.sendBtn.style.opacity = '0.5';
    this.input.style.pointerEvents = 'none';
    this.sendBtn.style.pointerEvents = 'none';
  };


  DecoupledChatbot.prototype.handleActionButton = function (action) {
    this.currentMode = action;

    // Disable buttons and enable input during workflow
    this.disableActionButtons();
    this.enableInput();

    if (action === 'model-content') {
      this.startModelContentFlow();
    } else if (action === 'answer-question') {
      this.startQuestionAnswerFlow();
    }
  };

  DecoupledChatbot.prototype.startModelContentFlow = function () {
    this.modelContentStep = 'description';
    this.addMessage('Describe the content type you want to create (e.g., "blog post with title, body, author, and tags").', 'bot');
    this.input.placeholder = 'Describe your content type...';
    this.input.focus();
  };

  DecoupledChatbot.prototype.startQuestionAnswerFlow = function () {
    this.addMessage('I\'m here to help answer your questions about Decoupled Drupal! What would you like to know?', 'bot');
    this.input.placeholder = 'Ask your question...';
    this.input.focus();
  };

  DecoupledChatbot.prototype.sendMessage = function () {
    const message = this.input.value.trim();
    if (!message || this.sendBtn.disabled) {
      return;
    }

    // Add user message to UI
    this.addMessage(message, 'user');

    // Clear input and disable send button
    this.input.value = '';
    this.setSendingState(true);

    if (this.currentMode === 'model-content') {
      this.handleModelContentMessage(message);
    } else {
      this.handleQuestionAnswerMessage(message);
    }
  };

  DecoupledChatbot.prototype.handleModelContentMessage = function (message) {
    if (this.modelContentStep === 'description') {
      // Process the content description and generate import configuration
      this.callModelContentAPI(message)
        .then(response => {
          this.addMessageWithStartOver(response.response, 'bot');
          this.hideLoading(); // Hide loading indicator immediately
          this.setSendingState(false);
          // After model content creation, disable input and hide buttons
          this.disableInput();
          this.hideActionButtons();
          this.currentMode = null;
          this.modelContentStep = null;
        })
        .catch(error => {
          console.error('Model content API error:', error);
          this.addMessage('Sorry, I encountered an error while generating your content model. Please try again.', 'bot', true);
          this.hideLoading();
          this.setSendingState(false);
          this.enableActionButtons();
          this.currentMode = null;
          this.modelContentStep = null;
        });
    }
  };

  DecoupledChatbot.prototype.handleQuestionAnswerMessage = function (message) {
    // Send to regular chat API
    this.callChatAPI(message)
      .then(response => {
        this.addMessage(response.response, 'bot');
        this.setSendingState(false);
        // Keep buttons disabled after Q&A completion, keep input enabled for follow-up
        this.currentMode = null;
        this.input.focus();
      })
      .catch(error => {
        console.error('Chat API error:', error);
        this.addMessage('Sorry, I encountered an error. Please try again later.', 'bot', true);
        this.setSendingState(false);
        // On error, allow user to try the buttons again
        this.enableActionButtons();
        this.currentMode = null;
        this.input.focus();
      });
  };

  DecoupledChatbot.prototype.addMessage = function (content, sender, isError = false) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `dc-chatbot-message ${sender}-message${isError ? ' error-message' : ''}`;

    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';

    // Render markdown for bot messages, plain text for user messages
    if (sender === 'bot') {
      contentDiv.innerHTML = this.parseMarkdown(content);
    } else {
      contentDiv.textContent = content;
    }

    const timeDiv = document.createElement('div');
    timeDiv.className = 'message-time';
    timeDiv.textContent = this.formatTime(new Date());

    messageDiv.appendChild(contentDiv);
    messageDiv.appendChild(timeDiv);

    this.messages.appendChild(messageDiv);

    // Scroll to bottom
    this.messages.scrollTop = this.messages.scrollHeight;

    // Store in history
    this.messageHistory.push({
      content: content,
      sender: sender,
      timestamp: Date.now(),
      isError: isError
    });
  };

  DecoupledChatbot.prototype.addMessageWithStartOver = function (content, sender, isError = false) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `dc-chatbot-message ${sender}-message${isError ? ' error-message' : ''}`;

    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';

    // Render markdown for bot messages, plain text for user messages
    if (sender === 'bot') {
      contentDiv.innerHTML = this.parseMarkdown(content);
    } else {
      contentDiv.textContent = content;
    }

    // Add "Start Over" button for bot messages
    if (sender === 'bot') {
      const startOverDiv = document.createElement('div');
      startOverDiv.className = 'start-over-container';
      startOverDiv.style.marginTop = '12px';
      startOverDiv.style.textAlign = 'center';

      const startOverBtn = document.createElement('button');
      startOverBtn.className = 'start-over-btn';
      startOverBtn.textContent = 'Start Over';
      startOverBtn.style.cssText = `
        padding: 8px 16px;
        border: none;
        border-radius: 16px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: white;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(79, 70, 229, 0.2);
      `;

      // Add hover effects with JavaScript since we're using inline styles
      startOverBtn.addEventListener('mouseenter', () => {
        startOverBtn.style.background = 'linear-gradient(135deg, #5b52f5 0%, #8b5cf6 100%)';
        startOverBtn.style.transform = 'translateY(-1px)';
        startOverBtn.style.boxShadow = '0 4px 8px rgba(79, 70, 229, 0.3)';
      });

      startOverBtn.addEventListener('mouseleave', () => {
        startOverBtn.style.background = 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)';
        startOverBtn.style.transform = 'translateY(0)';
        startOverBtn.style.boxShadow = '0 2px 6px rgba(79, 70, 229, 0.2)';
      });

      startOverBtn.addEventListener('click', () => {
        this.startOver();
      });

      startOverDiv.appendChild(startOverBtn);
      contentDiv.appendChild(startOverDiv);
    }

    const timeDiv = document.createElement('div');
    timeDiv.className = 'message-time';
    timeDiv.textContent = this.formatTime(new Date());

    messageDiv.appendChild(contentDiv);
    messageDiv.appendChild(timeDiv);

    this.messages.appendChild(messageDiv);

    // Scroll to bottom
    this.messages.scrollTop = this.messages.scrollHeight;

    // Store in history
    this.messageHistory.push({
      content: content,
      sender: sender,
      timestamp: Date.now(),
      isError: isError
    });
  };

  DecoupledChatbot.prototype.startOver = function () {
    // Clear all messages except the welcome message
    const welcomeMessage = this.messages.querySelector('#dc-chatbot-welcome');
    this.messages.innerHTML = '';
    if (welcomeMessage) {
      this.messages.appendChild(welcomeMessage);
    }

    // Add the initial options back
    const initialOptionsClone = this.initialOptions.cloneNode(true);
    this.messages.appendChild(initialOptionsClone);

    // Re-bind action button events for the cloned buttons
    const actionButtons = initialOptionsClone.querySelectorAll('.chatbot-action-btn');
    actionButtons.forEach(button => {
      button.addEventListener('click', (e) => {
        const action = button.getAttribute('data-action');
        this.handleActionButton(action);
      });
    });

    // Reset state
    this.currentMode = null;
    this.modelContentStep = null;
    this.messageHistory = [];

    // Return to initial state: buttons enabled, input disabled
    this.disableInput();
    this.enableActionButtons();

    // Clear input
    this.input.value = '';
    this.input.placeholder = 'Type your message...';

    // Update welcome time
    this.updateWelcomeTime();

    // Scroll to top
    this.messages.scrollTop = 0;
  };

  DecoupledChatbot.prototype.setSendingState = function (sending) {
    this.sendBtn.disabled = sending;
    this.input.disabled = sending;

    if (sending) {
      this.showLoading();
    } else {
      this.hideLoading();
    }
  };

  DecoupledChatbot.prototype.showLoading = function () {
    console.log('Decoupled Drupal Chatbot: Showing loading');
    if (this.loading) {
      // Add loading indicator to messages area
      this.messages.appendChild(this.loading);
      this.loading.setAttribute('aria-hidden', 'false');
      this.loading.style.display = 'block';

      // Scroll to bottom to show loading
      this.messages.scrollTop = this.messages.scrollHeight;
    }
  };

  DecoupledChatbot.prototype.hideLoading = function () {
    console.log('Decoupled Drupal Chatbot: Hiding loading');
    if (this.loading && this.loading.parentNode === this.messages) {
      this.loading.setAttribute('aria-hidden', 'true');
      this.loading.style.display = 'none';

      // Remove from messages area
      this.messages.removeChild(this.loading);
    }
  };

  DecoupledChatbot.prototype.callChatAPI = function (message) {
    // Call the local Drupal API endpoint
    return fetch('/api/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        message: message,
        context: {
          spaceId: this.getSpaceId(),
          timestamp: Date.now(),
        }
      })
    })
      .then(response => {
        if (!response.ok) {
          return response.json().then(errorData => {
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
          }).catch(() => {
            throw new Error(`HTTP error! status: ${response.status}`);
          });
        }
        return response.json();
      })
      .then(data => {
        if (data.error) {
          throw new Error(data.error);
        }
        return data;
      });
  };

  DecoupledChatbot.prototype.callModelContentAPI = function (contentDescription) {
    // For now, use the Drupal endpoint with mode parameter
    return fetch('/api/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        message: contentDescription,
        mode: 'model-content',
        context: {
          spaceId: this.getSpaceId(),
          timestamp: Date.now(),
        }
      })
    })
      .then(response => {
        if (!response.ok) {
          return response.json().then(errorData => {
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
          }).catch(() => {
            throw new Error(`HTTP error! status: ${response.status}`);
          });
        }
        return response.json();
      })
      .then(data => {
        if (data.error) {
          throw new Error(data.error);
        }
        return data;
      });
  };

  // Helper method to extract space ID from URL or configuration
  DecoupledChatbot.prototype.getSpaceId = function () {
    // Try to get from drupalSettings first
    if (drupalSettings.decoupledChatbot && drupalSettings.decoupledChatbot.spaceId) {
      return drupalSettings.decoupledChatbot.spaceId;
    }

    // For ddev.site URLs, return the full hostname
    const hostname = window.location.hostname;

    if (hostname.includes('.ddev.site')) {
      return hostname; // Return full hostname for ddev sites
    }

    const parts = hostname.split('.');

    // If it's a subdomain pattern like "spacename.domain.com"
    if (parts.length >= 3) {
      return parts[0]; // Return the subdomain as space identifier
    }

    // Fallback: return the full hostname
    return hostname;
  };

  // Helper method to determine Next.js API URL
  DecoupledChatbot.prototype.getNextjsApiUrl = function () {
    // In development, the Next.js app typically runs on localhost:3333
    // In production, this should be configured via drupalSettings
    if (drupalSettings.decoupledChatbot && drupalSettings.decoupledChatbot.nextjsApiUrl) {
      return drupalSettings.decoupledChatbot.nextjsApiUrl;
    }

    // Development fallback
    if (window.location.hostname.includes('localhost') || window.location.hostname.includes('127.0.0.1')) {
      return 'http://localhost:3333';
    }

    // Production fallback - assume same domain but different port or subdomain
    return 'https://dashboard.' + window.location.hostname.replace(/^[^.]+\./, '');
  };

  DecoupledChatbot.prototype.parseMarkdown = function (text) {
    // Escape HTML to prevent XSS
    text = text.replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    // Convert newlines to line breaks for processing
    text = text.replace(/\n/g, '<br>');

    // Parse markdown elements
    // Bold text **text**
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

    // Italic text *text* (but not at start of line to avoid conflicts with lists)
    text = text.replace(/(?<!^|\s)\*(.*?)\*/g, '<em>$1</em>');

    // Code `code`
    text = text.replace(/`(.*?)`/g, '<code>$1</code>');

    // Headers (simple # support)
    text = text.replace(/^### (.*$)/gim, '<h3>$1</h3>');
    text = text.replace(/^## (.*$)/gim, '<h2>$1</h2>');
    text = text.replace(/^# (.*$)/gim, '<h1>$1</h1>');

    // Links [text](url)
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Process lists more carefully
    const lines = text.split('<br>');
    let result = [];
    let inList = false;
    let listType = null;

    for (let i = 0; i < lines.length; i++) {
      let line = lines[i].trim();

      // Check if this is a list item
      const ulMatch = line.match(/^\* (.*)$/);
      const olMatch = line.match(/^\d+\. (.*)$/);

      if (ulMatch) {
        // Unordered list item
        if (!inList || listType !== 'ul') {
          if (inList) {
            result.push(`</${listType}>`);
          }
          result.push('<ul>');
          listType = 'ul';
          inList = true;
        }
        result.push(`<li>${ulMatch[1]}</li>`);
      } else if (olMatch) {
        // Ordered list item
        if (!inList || listType !== 'ol') {
          if (inList) {
            result.push(`</${listType}>`);
          }
          result.push('<ol>');
          listType = 'ol';
          inList = true;
        }
        result.push(`<li>${olMatch[1]}</li>`);
      } else {
        // Not a list item
        if (inList) {
          result.push(`</${listType}>`);
          inList = false;
          listType = null;
        }
        if (line) {
          // Handle paragraphs - wrap non-header, non-list content in <p> tags
          // But don't wrap single bullet points in <p> tags
          if (!line.match(/^<h[123]>/) && !line.match(/^•/) && line.length > 0) {
            result.push(`<p>${line}</p>`);
          } else if (line.match(/^•/)) {
            // Handle standalone bullet points that aren't in lists
            // Remove the bullet character since CSS will add it
            const cleanedLine = line.replace(/^•\s*/, '');
            result.push(`<div class="bullet-item">${cleanedLine}</div>`);
          } else {
            result.push(line);
          }
        }
      }
    }

    // Close any open list
    if (inList) {
      result.push(`</${listType}>`);
    }

    // Join without <br> since we're using proper HTML elements now
    text = result.join('');

    // Clean up any remaining <br> tags that might interfere
    text = text.replace(/<br>/g, '');

    return text;
  };

  DecoupledChatbot.prototype.formatTime = function (date) {
    return date.toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  // Export for potential external use
  window.DecoupledChatbot = DecoupledChatbot;

})(Drupal, drupalSettings);
