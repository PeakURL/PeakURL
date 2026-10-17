/* Enhance the native language field without changing its submitted name or values. */
(() => {
	document.addEventListener(
		"DOMContentLoaded",
		() => {
			const select = document.querySelector(".language-picker-select");
			if (!select || !select.form || select.options.length === 0) return;
			const label = document.querySelector(`label[for="${select.id}"]`);
			const icon = (path) => {
				const svg = document.createElementNS(
					"http://www.w3.org/2000/svg",
					"svg"
				);
				svg.setAttribute("viewBox", "0 0 24 24");
				svg.setAttribute("fill", "none");
				svg.setAttribute("stroke", "currentColor");
				svg.setAttribute("stroke-width", "2");
				svg.setAttribute("stroke-linecap", "round");
				svg.setAttribute("stroke-linejoin", "round");
				svg.setAttribute("aria-hidden", "true");
				const line = document.createElementNS(svg.namespaceURI, "path");
				line.setAttribute("d", path);
				svg.append(line);
				return svg;
			};
			const trigger = document.createElement("button");
			trigger.type = "button";
			trigger.id = `${select.id}-trigger`;
			trigger.className = "installer-language-trigger";
			trigger.setAttribute("role", "combobox");
			trigger.setAttribute("aria-haspopup", "listbox");
			trigger.setAttribute("aria-expanded", "false");
			const value = document.createElement("span");
			value.textContent = select.selectedOptions[0].textContent;
			trigger.append(value, icon("m6 9 6 6 6-6"));
			const popup = document.createElement("div");
			popup.id = `${select.id}-options`;
			popup.className = "installer-language-options";
			popup.setAttribute("role", "listbox");
			popup.hidden = true;
			trigger.setAttribute("aria-controls", popup.id);
			if (label) {
				label.id = `${select.id}-label`;
				label.htmlFor = trigger.id;
				trigger.setAttribute("aria-labelledby", label.id);
				popup.setAttribute("aria-labelledby", label.id);
			}
			const options = Array.from(select.options, (option, index) => {
				const row = document.createElement("div");
				row.id = `${select.id}-option-${index}`;
				row.className = "installer-language-option";
				row.setAttribute("role", "option");
				row.setAttribute("aria-selected", String(option.selected));
				const text = document.createElement("span");
				text.textContent = option.textContent;
				row.append(text, icon("m5 12 4 4L19 6"));
				popup.append(row);
				return row;
			});
			let activeIndex = select.selectedIndex;
			let typed = "";
			let lastTypedAt = 0;
			const TYPEAHEAD_INTERVAL_MS = 700;
			const positionPopup = () => {
				if (popup.hidden) return;
				const bounds = trigger.getBoundingClientRect();
				const viewportHeight =
					window.visualViewport?.height ?? innerHeight;
				const gap = 6;
				const edge = 12;
				const below = viewportHeight - bounds.bottom - gap - edge;
				const above = bounds.top - gap - edge;
				const opensAbove = below < 160 && above > below;
				popup.style.width = `${bounds.width}px`;
				popup.style.maxHeight = `${Math.max(44, Math.min(256, opensAbove ? above : below))}px`;
				popup.style.left = `${Math.max(edge, Math.min(bounds.left, innerWidth - bounds.width - edge))}px`;
				popup.style.top = `${opensAbove ? bounds.top - gap - popup.offsetHeight : bounds.bottom + gap}px`;
			};
			const highlight = (index, scroll = true) => {
				activeIndex = Math.max(0, Math.min(options.length - 1, index));
				options.forEach((row, rowIndex) =>
					row.toggleAttribute("data-active", rowIndex === activeIndex)
				);
				trigger.setAttribute(
					"aria-activedescendant",
					options[activeIndex].id
				);
				if (scroll)
					options[activeIndex].scrollIntoView({ block: "nearest" });
			};
			const close = () => {
				popup.hidden = true;
				trigger.setAttribute("aria-expanded", "false");
				trigger.removeAttribute("aria-activedescendant");
				typed = "";
			};
			const open = () => {
				popup.hidden = false;
				popup.scrollTop = 0;
				trigger.setAttribute("aria-expanded", "true");
				positionPopup();
				highlight(select.selectedIndex);
			};
			const choose = (index) => {
				close();
				trigger.focus();
				if (index === select.selectedIndex) return;
				select.selectedIndex = index;
				value.textContent = select.selectedOptions[0].textContent;
				options.forEach((row, rowIndex) =>
					row.setAttribute(
						"aria-selected",
						String(rowIndex === index)
					)
				);
				select.dispatchEvent(new Event("change", { bubbles: true }));
			};
			trigger.addEventListener("click", () =>
				popup.hidden ? open() : close()
			);
			trigger.addEventListener("keydown", (event) => {
				const wasOpen = !popup.hidden;
				if (event.altKey && event.key === "ArrowUp") {
					event.preventDefault();
					if (wasOpen) choose(activeIndex);
					return;
				}
				if (
					[
						"ArrowDown",
						"ArrowUp",
						"Home",
						"End",
						"PageDown",
						"PageUp",
					].includes(event.key)
				) {
					event.preventDefault();
					if (!wasOpen) open();
					const movement = {
						ArrowDown: 1,
						ArrowUp: -1,
						PageDown: 10,
						PageUp: -10,
					};
					highlight(
						event.key === "Home"
							? 0
							: event.key === "End"
								? options.length - 1
								: wasOpen
									? activeIndex + movement[event.key]
									: activeIndex
					);
				} else if (event.key === "Enter" || event.key === " ") {
					event.preventDefault();
					if (wasOpen) choose(activeIndex);
					else open();
				} else if (event.key === "Escape") {
					event.preventDefault();
					close();
				} else if (event.key === "Tab") {
					if (wasOpen) choose(activeIndex);
				} else if (
					event.key.length === 1 &&
					!event.ctrlKey &&
					!event.metaKey &&
					!event.altKey
				) {
					event.preventDefault();
					if (!wasOpen) open();
					const now = Date.now();
					typed =
						now - lastTypedAt > TYPEAHEAD_INTERVAL_MS
							? event.key
							: typed + event.key;
					lastTypedAt = now;
					const repeated = Array.from(typed).every(
						(character) => character === typed[0]
					);
					const query = (
						repeated ? event.key : typed
					).toLocaleLowerCase();
					for (
						let offset = repeated ? 1 : 0;
						offset < options.length + (repeated ? 1 : 0);
						offset++
					) {
						const index = (activeIndex + offset) % options.length;
						if (
							options[index].textContent
								.trim()
								.toLocaleLowerCase()
								.startsWith(query)
						) {
							highlight(index);
							break;
						}
					}
				}
			});
			options.forEach((row, index) => {
				row.addEventListener("pointermove", () =>
					highlight(index, false)
				);
				row.addEventListener("click", () => choose(index));
			});
			popup.addEventListener("mousedown", (event) =>
				event.preventDefault()
			);
			document.addEventListener("pointerdown", (event) => {
				if (
					!trigger.contains(event.target) &&
					!popup.contains(event.target)
				)
					close();
			});
			trigger.addEventListener("blur", close);
			window.addEventListener("resize", positionPopup);
			window.addEventListener("scroll", positionPopup, true);
			window.visualViewport?.addEventListener("resize", positionPopup);
			// A body-level popup avoids clipping at the install panel's rounded corners.
			document.body.append(popup);
			select.after(trigger);
			select.hidden = true;
		},
		{ once: true }
	);
})();
